<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\StudentFee;
use App\Modules\Finance\Services\AccountReader;
use App\Modules\Finance\Services\FeeBilling;
use App\Modules\Finance\Services\FinanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The school's money: yearly fees per grade, fees and installments of students, payments with receipts, balances.
 * The accountant manages everything; the admin can read it.
 */
class FinanceController extends Controller
{
    public function __construct(private readonly FeeBilling $billing, private readonly AccountReader $reader) {}

    // ---- reading (accountant and admin) -------------------------------------------------------

    public function summary(Request $request): JsonResponse
    {
        $this->view($request);

        return response()->json(['data' => $this->reader->summary($this->year()) + ['currency' => $this->currency()]]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $this->view($request);
        $filters = $request->validate([
            'grade_level_id' => ['sometimes', 'integer'], 'classroom_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(['overdue', 'owing', 'settled'])], 'search' => ['sometimes', 'string', 'max:100'],
        ]);

        return response()->json($this->reader->accounts($this->year(), $filters)->toArray() + ['currency' => $this->currency()]);
    }

    public function statement(Request $request, Student $student): JsonResponse
    {
        $this->view($request);

        return response()->json(['data' => $this->reader->statement($student) + ['currency' => $this->currency()]]);
    }

    /** The grades and sections of this year, for the filters (the accountant cannot read the admin's school-structure pages). */
    public function options(Request $request): JsonResponse
    {
        $this->view($request);
        $year = $this->year();

        return response()->json(['data' => [
            'grades' => GradeLevel::orderBy('sort_order')->get(['id', 'name_ar', 'name_en']),
            'classrooms' => Classroom::where('academic_year_id', $year->id)->orderBy('name')->get(['id', 'name', 'grade_level_id']),
        ]]);
    }

    public function structures(Request $request): JsonResponse
    {
        $this->view($request);
        $rows = FeeStructure::with('gradeLevel')->where('academic_year_id', $this->year()->id)->orderBy('grade_level_id')->orderBy('name')->get();
        $given = StudentFee::whereIn('fee_structure_id', $rows->pluck('id'))->selectRaw('fee_structure_id, count(*) as n')->groupBy('fee_structure_id')->pluck('n', 'fee_structure_id');

        return response()->json(['data' => $rows->map(fn (FeeStructure $s) => [
            'id' => $s->id, 'name' => $s->name, 'amount' => $s->amount, 'grade_level' => $s->gradeLevel->only(['id', 'name_ar', 'name_en']), 'students' => (int) ($given[$s->id] ?? 0),
            'installments_count' => $s->installments_count, 'first_due' => $s->first_due?->format('Y-m-d'), 'every_months' => $s->every_months, 'auto_apply' => $s->auto_apply,
        ])->values(), 'currency' => $this->currency()]);
    }

    // ---- managing (accountant only) ---------------------------------------------------------------

    public function storeStructure(Request $request): JsonResponse
    {
        $this->manage($request);
        $s = FeeStructure::create($this->structureData($request) + ['academic_year_id' => $this->year()->id]);

        return response()->json(['data' => ['id' => $s->id]], 201);
    }

    public function updateStructure(Request $request, FeeStructure $structure): JsonResponse
    {
        $this->manage($request);
        $structure->update($this->structureData($request));

        return response()->json(['data' => ['id' => $structure->id]]);
    }

    public function destroyStructure(Request $request, FeeStructure $structure): Response
    {
        $this->manage($request);
        if (StudentFee::where('fee_structure_id', $structure->id)->exists()) {
            throw ValidationException::withMessages(['structure' => [__('finance.already_paid')]]);
        }
        $structure->delete();

        return response()->noContent();
    }

    /** Gives a structure's fee, split into installments, to every student of its grade who does not have it yet. */
    public function applyStructure(Request $request, FeeStructure $structure): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate([
            'installments' => ['sometimes', 'integer', 'between:1,24'], 'first_due' => ['sometimes', 'date_format:Y-m-d'],
            'every_months' => ['sometimes', 'integer', 'between:1,12'], 'discount' => ['sometimes', 'numeric', 'min:0'],
        ]);
        $firstDue = $data['first_due'] ?? $structure->first_due?->toDateString();
        if ($firstDue === null) {
            throw ValidationException::withMessages(['first_due' => [__('finance.no_plan')]]);
        }

        // Without options the fee's own plan is used.
        $count = $this->billing->applyStructure($structure->load('academicYear'), $data['installments'] ?? $structure->installments_count, $firstDue, $data['every_months'] ?? $structure->every_months, (float) ($data['discount'] ?? 0));

        return response()->json(['data' => ['students' => $count]]);
    }

    /** A fee for one student (a bus, a discount case...): an even split, or the installments written by hand. */
    public function storeFee(Request $request, Student $student): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'], 'total_amount' => ['required', 'numeric', 'gt:0'], 'discount' => ['sometimes', 'numeric', 'min:0'],
            'installments' => ['required_without:installments_count', 'array', 'min:1', 'max:24'], 'installments.*.due_date' => ['required', 'date_format:Y-m-d'], 'installments.*.amount' => ['required', 'numeric', 'gt:0'],
            'installments_count' => ['required_without:installments', 'integer', 'between:1,24'], 'first_due' => ['required_with:installments_count', 'date_format:Y-m-d'], 'every_months' => ['sometimes', 'integer', 'between:1,12'],
        ]);
        $discount = (float) ($data['discount'] ?? 0);
        $schedule = $data['installments'] ?? $this->billing->evenSchedule(Money::cents($data['total_amount']) - Money::cents($discount), $data['installments_count'], $data['first_due'], $data['every_months'] ?? 1);

        $fee = $this->billing->createFee($student, $this->year(), $data['description'], $data['total_amount'], $discount, $schedule);

        return response()->json(['data' => ['id' => $fee->id]], 201);
    }

    public function updateFee(Request $request, StudentFee $fee): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['discount' => ['required', 'numeric', 'min:0']]);
        $this->billing->changeDiscount($fee, $data['discount']);

        return response()->json(['data' => ['id' => $fee->id]]);
    }

    public function destroyFee(Request $request, StudentFee $fee): Response
    {
        $this->manage($request);
        if ($fee->payments()->exists()) {
            throw ValidationException::withMessages(['fee' => [__('finance.already_paid')]]);
        }
        $fee->delete();

        return response()->noContent();
    }

    public function pay(Request $request): JsonResponse
    {
        $user = $this->manage($request);
        $data = $request->validate([
            'student_fee_id' => ['required', 'integer', 'exists:student_fees,id'], 'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(Payment::METHODS)], 'paid_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:255'], 'installment_id' => ['nullable', 'integer'],
        ]);

        $payment = $this->billing->recordPayment(StudentFee::findOrFail($data['student_fee_id']), $data['amount'], $data['method'], $data['paid_on'] ?? now()->toDateString(), $user, $data['reference'] ?? null, $data['note'] ?? null, $data['installment_id'] ?? null);

        return response()->json(['data' => $this->reader->payment($payment->load('receiver'))], 201);
    }

    /** Cancels a payment made by mistake. */
    public function voidPayment(Request $request, Payment $payment): Response
    {
        $this->manage($request);
        $this->billing->voidPayment($payment);

        return response()->noContent();
    }

    public function currency(): string
    {
        return (string) (DB::table('school_settings')->where('key', 'currency')->value('value') ?: 'JOD');
    }

    public function settings(Request $request): JsonResponse
    {
        $this->view($request);

        return response()->json(['data' => ['currency' => $this->currency(), 'can_manage' => FinanceAccess::manages($request->user())]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['currency' => ['required', 'string', 'min:1', 'max:8']]);
        DB::table('school_settings')->updateOrInsert(['key' => 'currency'], ['value' => $data['currency'], 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['data' => ['currency' => $data['currency']]]);
    }

    private function view(Request $request): User
    {
        $user = $request->user();
        abort_unless(FinanceAccess::views($user), 403, __('finance.not_allowed'));

        return $user;
    }

    private function manage(Request $request): User
    {
        $user = $request->user();
        abort_unless(FinanceAccess::manages($user), 403, __('finance.not_allowed'));

        return $user;
    }

    private function year(): AcademicYear
    {
        $year = AcademicYear::where('is_current', true)->first();
        if ($year === null) {
            throw ValidationException::withMessages(['academic_year' => [__('finance.no_year')]]);
        }

        return $year;
    }

    /** @return array<string, mixed> */
    private function structureData(Request $request): array
    {
        return $request->validate([
            'grade_level_id' => ['required', 'integer', 'exists:grade_levels,id'], 'name' => ['required', 'string', 'max:255'], 'amount' => ['required', 'numeric', 'gt:0'],
            'installments_count' => ['required', 'integer', 'between:1,24'], 'first_due' => ['required', 'date_format:Y-m-d'], 'every_months' => ['required', 'integer', 'between:1,12'], 'auto_apply' => ['sometimes', 'boolean'],
        ]);
    }
}
