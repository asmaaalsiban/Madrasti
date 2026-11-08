<?php

declare(strict_types=1);

namespace App\Modules\Discipline\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Discipline\Models\Warning;
use App\Modules\Discipline\Services\Warnings;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Warnings to students and to staff. The admin writes them (they are active at once) and approves or withdraws; a teacher can propose
 * one for a student (it waits for the admin). A student's warnings are on the student's page and the parents' page; a parent can say
 * they have seen it; a member of staff sees their own.
 */
class WarningController extends Controller
{
    public function __construct(private readonly Warnings $warnings) {}

    /** The admin: every warning (filters: subject, status, student, user). A teacher: the ones they wrote. */
    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['subject_type' => ['nullable', Rule::in(['student', 'staff'])], 'status' => ['nullable', Rule::in(['pending', 'active', 'withdrawn'])], 'student_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $rows = Warning::with(['student:id,name_ar,name_en', 'user:id,name', 'issuer:id,name'])
            ->when(! $user->hasRole(Role::Admin), fn ($w) => $w->where('issued_by', $user->id))
            ->when(isset($q['subject_type']), fn ($w) => $w->where('subject_type', $q['subject_type']))->when(isset($q['status']), fn ($w) => $w->where('status', $q['status']))
            ->when(isset($q['student_id']), fn ($w) => $w->where('student_id', $q['student_id']))->when(isset($q['user_id']), fn ($w) => $w->where('user_id', $q['user_id']))
            ->orderByDesc('issued_on')->orderByDesc('id')->limit(300)->get();

        return response()->json(['data' => $rows->map(fn (Warning $w) => $this->present($w)), 'can_manage' => $user->hasRole(Role::Admin)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate([
            'subject_type' => ['required', Rule::in(['student', 'staff'])],
            'student_id' => ['required_if:subject_type,student', 'nullable', 'integer', 'exists:students,id'],
            'user_id' => ['required_if:subject_type,staff', 'nullable', 'integer', 'exists:users,id'],
            'category' => ['required', Rule::in(Warning::CATEGORIES)], 'level' => ['required', Rule::in(Warning::LEVELS)],
            'title' => ['required', 'string', 'max:150'], 'body' => ['nullable', 'string', 'max:4000'], 'issued_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        $admin = $user->hasRole(Role::Admin);
        if ($d['subject_type'] === 'staff') {
            abort_unless($admin, 403, __('discipline.admin_only'));
            if (! User::whereKey($d['user_id'])->whereHas('staffProfile')->exists()) {
                throw ValidationException::withMessages(['user_id' => [__('discipline.not_staff')]]);
            }
        }
        $w = Warning::create([
            'subject_type' => $d['subject_type'], 'student_id' => $d['subject_type'] === 'student' ? $d['student_id'] : null, 'user_id' => $d['subject_type'] === 'staff' ? $d['user_id'] : null,
            'kind' => 'manual', 'category' => $d['category'], 'level' => $d['level'], 'title' => $d['title'], 'body' => $d['body'] ?? null,
            'issued_on' => $d['issued_on'] ?? now()->toDateString(), 'status' => $admin ? 'active' : 'pending', 'issued_by' => $user->id,
        ]);
        if ($admin) {
            $this->warnings->release($w, $user);
        }

        return response()->json(['data' => $this->present($w->refresh()->load(['student:id,name_ar,name_en', 'user:id,name', 'issuer:id,name']))], 201);
    }

    /** The admin lets a proposed warning through: it becomes active and the notification goes out. */
    public function approve(Request $request, Warning $warning): JsonResponse
    {
        if ($warning->status !== 'pending') {
            throw ValidationException::withMessages(['warning' => [__('discipline.not_pending')]]);
        }
        $this->warnings->release($warning, $request->user());

        return response()->json(['data' => $this->present($warning->refresh()->load(['student:id,name_ar,name_en', 'user:id,name', 'issuer:id,name']))]);
    }

    /** Takes a warning back (a mistake, or it was settled): it disappears from the parents' page and stays in the admin's record. */
    public function withdraw(Request $request, Warning $warning): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $warning->update(['status' => 'withdrawn', 'withdrawn_at' => now(), 'withdrawn_reason' => $d['reason']]);

        return response()->json(['data' => $this->present($warning->load(['student:id,name_ar,name_en', 'user:id,name', 'issuer:id,name']))]);
    }

    /** A student's warnings (the student, the parents and the admin). Only the active ones, except for the admin. */
    public function forStudent(Request $request, Student $student): JsonResponse
    {
        Gate::authorize('view-student-homework', $student);
        $admin = $request->user()->hasRole(Role::Admin);
        $rows = Warning::with('issuer:id,name')->where('student_id', $student->id)->when(! $admin, fn ($q) => $q->where('status', 'active'))->orderByDesc('issued_on')->orderByDesc('id')->get();

        return response()->json(['data' => $rows->map(fn (Warning $w) => $this->present($w, $admin))]);
    }

    /** The signed-in student's own warnings, or a member of staff's own. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole(Role::Student) && $user->student !== null) {
            $rows = Warning::where('student_id', $user->student->id)->where('status', 'active');
        } else {
            abort_unless($user->hasRole(Role::Teacher), 403);
            $rows = Warning::where('user_id', $user->id)->where('subject_type', 'staff')->where('status', 'active');
        }

        return response()->json(['data' => $rows->orderByDesc('issued_on')->orderByDesc('id')->get()->map(fn (Warning $w) => $this->present($w))]);
    }

    /** "I have seen it": a parent of the student, or the member of staff the warning is for. */
    public function acknowledge(Request $request, Warning $warning): JsonResponse
    {
        $user = $request->user();
        $mayAck = $warning->status === 'active' && ($warning->subject_type === 'student'
            ? $user->hasRole(Role::Guardian) && $warning->student?->guardians()->whereKey($user->id)->exists()
            : $warning->user_id === $user->id);
        abort_unless($mayAck, 403);
        if ($warning->acknowledged_at === null) {
            $warning->update(['acknowledged_at' => now(), 'acknowledged_by' => $user->id]);
        }

        return response()->json(['data' => $this->present($warning->refresh())]);
    }

    /** @return array<string, mixed> */
    private function present(Warning $w, bool $admin = true): array
    {
        return [
            'id' => $w->id, 'subject_type' => $w->subject_type, 'kind' => $w->kind, 'category' => $w->category, 'level' => $w->level, 'title' => $w->title, 'body' => $w->body,
            'issued_on' => $w->issued_on->toDateString(), 'status' => $w->status, 'issued_by' => $w->relationLoaded('issuer') ? $w->issuer?->name : null,
            'student' => $w->relationLoaded('student') && $w->student ? $w->student->only(['id', 'name_ar', 'name_en']) : null,
            'user' => $w->relationLoaded('user') && $w->user ? $w->user->only(['id', 'name']) : null,
            'acknowledged_at' => $w->acknowledged_at?->toDateTimeString(),
            'withdrawn_reason' => $admin ? $w->withdrawn_reason : null,
        ];
    }
}
