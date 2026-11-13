<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\StudentRenewal;
use App\Modules\Academic\Services\RenewalService;
use App\Modules\Finance\Services\FinanceAccess;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The yearly re-registration of students: the school management and the accountant run it. */
class RenewalController extends Controller
{
    public function __construct(private readonly RenewalService $renewals) {}

    public function index(Request $request): JsonResponse
    {
        $this->allow($request);
        $years = AcademicYear::orderByDesc('starts_on')->get(['id', 'name', 'starts_on', 'is_current']);
        $to = $request->integer('year_id') ? AcademicYear::findOrFail($request->integer('year_id')) : $this->renewals->nextYear();
        $rows = $to === null ? collect() : StudentRenewal::with(['student:id,name_ar,name_en,student_number,gender', 'fromClassroom:id,name', 'fromGrade', 'toGrade', 'toClassroom:id,name'])
            ->where('to_year_id', $to->id)->whereHas('student') // a student the school deleted after opening is not listed
            ->get()->sortBy(fn ($r) => [$r->from_classroom_id, $r->student->name_ar])->values();

        return response()->json([
            'year' => $to ? ['id' => $to->id, 'name' => $to->name] : null,
            'current_year' => $this->renewals->currentYear()?->only(['id', 'name']),
            'years' => $years->map(fn ($y) => ['id' => $y->id, 'name' => $y->name, 'is_current' => $y->is_current])->values(),
            'summary' => collect(StudentRenewal::STATUSES)->mapWithKeys(fn ($s) => [$s => $rows->where('status', $s)->count()])->all() + ['total' => $rows->count()],
            'data' => $rows->map(fn (StudentRenewal $r) => $this->present($r))->values(),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate(['year_id' => ['required', 'integer', 'exists:academic_years,id']]);
        $added = $this->renewals->open(AcademicYear::findOrFail($data['year_id']));

        return response()->json(['added' => $added], 201);
    }

    /** Sections of a grade in the new year with how many students they hold, for choosing where a student goes. */
    public function sections(Request $request): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate(['year_id' => ['required', 'integer'], 'grade_level_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->renewals->sections((int) $data['year_id'], (int) $data['grade_level_id'])->map(fn ($s) => collect($s)->except('model'))->values()]);
    }

    public function renew(Request $request, StudentRenewal $renewal): JsonResponse
    {
        $by = $this->allow($request);
        $data = $request->validate([
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'], 'grade_level_id' => ['nullable', 'integer', 'exists:grade_levels,id'],
            'discount' => ['nullable', 'numeric', 'min:0'], 'transport_fee' => ['nullable', 'numeric', 'min:0'],
        ]);
        $fees = $this->renewals->renew($renewal->load(['student', 'toYear']), $by, isset($data['classroom_id']) ? (int) $data['classroom_id'] : null, isset($data['grade_level_id']) ? (int) $data['grade_level_id'] : null, (float) ($data['discount'] ?? 0), (float) ($data['transport_fee'] ?? 0));

        return response()->json(['data' => $this->present($this->fresh($renewal)), 'fees_created' => $fees]);
    }

    public function decline(Request $request, StudentRenewal $renewal): JsonResponse
    {
        $by = $this->allow($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->renewals->decline($renewal, $by, $data['reason'] ?? null);

        return response()->json(['data' => $this->present($this->fresh($renewal))]);
    }

    public function reopen(Request $request, StudentRenewal $renewal): JsonResponse
    {
        $this->allow($request);
        $this->renewals->reopen($renewal);

        return response()->json(['data' => $this->present($this->fresh($renewal))]);
    }

    /** Renews everyone still waiting, each in the best section of their next grade. */
    public function renewAll(Request $request): JsonResponse
    {
        $by = $this->allow($request);
        $data = $request->validate(['year_id' => ['required', 'integer', 'exists:academic_years,id']]);

        return response()->json($this->renewals->renewAll(AcademicYear::findOrFail($data['year_id']), $by));
    }

    private function fresh(StudentRenewal $r): StudentRenewal
    {
        return $r->refresh()->load(['student:id,name_ar,name_en,student_number,gender', 'fromClassroom:id,name', 'fromGrade', 'toGrade', 'toClassroom:id,name']);
    }

    /** @return array<string, mixed> */
    private function present(StudentRenewal $r): array
    {
        $grade = fn ($g) => $g ? ['id' => $g->id, 'name_ar' => $g->name_ar, 'name_en' => $g->name_en] : null;

        return [
            'id' => $r->id, 'status' => $r->status, 'decline_reason' => $r->decline_reason, 'decided_at' => $r->decided_at?->toIso8601String(),
            'student' => ['id' => $r->student->id, 'name_ar' => $r->student->name_ar, 'name_en' => $r->student->name_en, 'student_number' => $r->student->student_number],
            'from_classroom' => $r->fromClassroom?->name, 'from_grade' => $grade($r->fromGrade), 'to_grade' => $grade($r->toGrade), 'to_classroom' => $r->toClassroom?->name,
        ];
    }

    private function allow(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole(Role::Admin) || FinanceAccess::manages($user), 403, __('renewal.not_allowed'));

        return $user;
    }
}
