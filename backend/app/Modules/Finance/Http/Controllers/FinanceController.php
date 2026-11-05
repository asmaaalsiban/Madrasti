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
use App\Shared\Export\XlsxExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    /** The accounts list (same filters) as an Excel file: what each student owes, paid, remains and is overdue. */
    public function exportAccounts(Request $request, XlsxExport $xlsx): BinaryFileResponse
    {
        $this->view($request);
        $filters = $request->validate([
            'grade_level_id' => ['sometimes', 'integer'], 'classroom_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(['overdue', 'owing', 'settled'])], 'search' => ['sometimes', 'string', 'max:100'],
        ]);
        $currency = $this->currency();
        $rows = collect($this->reader->accounts($this->year(), $filters, 100000)->items())->map(fn ($r) => [
            $r['student_number'], $r['name_ar'], $r['name_en'], $r['classroom'], (float) $r['net'], (float) $r['paid'], (float) $r['remaining'], (float) $r['overdue'],
        ]);

        return $xlsx->download(__('reports.accounts_file').'_'.now()->toDateString(), __('reports.accounts_sheet'), [
            __('reports.student_number'), __('reports.name_ar'), __('reports.name_en'), __('reports.classroom'),
            __('reports.net', ['currency' => $currency]), __('reports.paid', ['currency' => $currency]), __('reports.remaining', ['currency' => $currency]), __('reports.overdue', ['currency' => $currency]),
        ], $rows);
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

    /**
     * Adds fees to a student. If the student already has a schedule, the amount is spread over the installments still open (never a
     * schedule of its own); otherwise the fee is created with its plan: a total, an optional transport fee and discount (all in the
     * same installments) and an optional first payment taken at once.
     */
    public function storeFee(Request $request, Student $student): JsonResponse
    {
        $user = $this->manage($request);
        $year = $this->year();
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'], 'total_amount' => ['required', 'numeric', 'gt:0'], 'kind' => ['sometimes', Rule::in(['transport', 'other'])],
            'discount' => ['sometimes', 'numeric', 'min:0'], 'transport_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'installments' => ['sometimes', 'array', 'min:1', 'max:24'], 'installments.*.due_date' => ['required_with:installments', 'date_format:Y-m-d'], 'installments.*.amount' => ['required_with:installments', 'numeric', 'gt:0'],
            'installments_count' => ['sometimes', 'integer', 'between:1,24'], 'first_due' => ['sometimes', 'date_format:Y-m-d'], 'every_months' => ['sometimes', 'integer', 'between:1,12'],
            'first_payment' => ['sometimes', 'nullable', 'numeric', 'min:0'], 'first_payment_method' => ['sometimes', Rule::in(Payment::METHODS)], 'payer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $main = $this->billing->mainFee($student, $year);
        if ($main !== null) {
            $this->billing->addCharge($main, $data['kind'] ?? 'other', $data['description'], $data['total_amount']);

            return response()->json(['data' => ['id' => $main->id, 'spread' => true]], 201);
        }

        $discount = (float) ($data['discount'] ?? 0);
        $transport = (float) ($data['transport_fee'] ?? 0);
        $total = Money::cents($data['total_amount']) + Money::cents($transport);
        $net = $total - Money::cents($discount);
        if (! isset($data['installments']) && (! isset($data['installments_count']) || ! isset($data['first_due']))) {
            throw ValidationException::withMessages(['installments' => [__('finance.schedule_mismatch')]]);
        }
        $schedule = $data['installments'] ?? $this->billing->evenSchedule($net, $data['installments_count'], $data['first_due'], $data['every_months'] ?? 1);
        $items = array_values(array_filter([
            ['kind' => ($data['kind'] ?? 'other') === 'transport' ? 'transport' : 'other', 'description' => $data['description'], 'amount' => $data['total_amount']],
            $transport > 0 ? ['kind' => 'transport', 'description' => __('finance.transport'), 'amount' => $transport] : null,
            $discount > 0 ? ['kind' => 'discount', 'description' => __('finance.discount_line'), 'amount' => -1 * $discount] : null,
        ]));

        return DB::transaction(function () use ($student, $year, $data, $total, $discount, $schedule, $items, $user): JsonResponse {
            $fee = $this->billing->createFee($student, $year, $data['description'], Money::amount($total), $discount, $schedule, null, ($data['kind'] ?? 'other') === 'transport' ? 'transport' : 'other', $items);
            $payments = [];
            if ((float) ($data['first_payment'] ?? 0) > 0) {
                $payments = $this->billing->payFirst([$fee], (float) $data['first_payment'], $data['first_payment_method'] ?? 'cash', $user, $data['payer_name'] ?? null);
            }

            return response()->json(['data' => ['id' => $fee->id, 'spread' => false, 'payments' => array_map(fn (Payment $p) => $this->reader->payment($p->load('receiver')), $payments)]], 201);
        });
    }

    /** Sets the student's transport fee from their account page (0 = no transport); same rules as the student form. */
    public function setTransport(Request $request, Student $student): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0', 'max:1000000']]);
        $year = $this->year();
        $classroomId = $student->enrollments()->where('academic_year_id', $year->id)->value('classroom_id');
        if ($classroomId === null) {
            throw ValidationException::withMessages(['amount' => [__('finance.not_enrolled')]]);
        }

        $fee = $this->billing->setTransport($student, Classroom::findOrFail($classroomId), (float) $data['amount']);

        return response()->json(['data' => ['id' => $fee?->id]]);
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
            'reference' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:255'], 'installment_id' => ['nullable', 'integer'], 'payer_name' => ['nullable', 'string', 'max:255'],
            'money_account_id' => ['nullable', 'integer'],
        ]);

        $payment = $this->billing->recordPayment(StudentFee::findOrFail($data['student_fee_id']), $data['amount'], $data['method'], $data['paid_on'] ?? now()->toDateString(), $user, $data['reference'] ?? null, $data['note'] ?? null, $data['installment_id'] ?? null, $data['payer_name'] ?? null, $data['money_account_id'] ?? null);

        return response()->json(['data' => $this->reader->payment($payment->load('receiver'))], 201);
    }

    /** Cancels a payment made by mistake. */
    public function voidPayment(Request $request, Payment $payment): Response
    {
        $this->manage($request);
        if ($payment->method === Payment::CARRY) {
            throw ValidationException::withMessages(['payment' => [__('finance.carry_use_year_end')]]);
        }
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
