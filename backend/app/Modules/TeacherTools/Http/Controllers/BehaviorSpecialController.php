<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\Student;
use App\Modules\TeacherTools\Models\BehaviorStudentCriterion;
use App\Modules\TeacherTools\Services\BehaviorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A student's special evaluation: criteria written for that student alone. Whoever may evaluate the student (their
 * teachers, the admin) reads and manages them; they appear only in that student's evaluation, never for the others.
 */
class BehaviorSpecialController extends Controller
{
    public function __construct(private readonly BehaviorAccess $access) {}

    public function index(Request $request, Student $student): JsonResponse
    {
        $this->authorizeStudent($request, $student);

        return response()->json(['data' => $this->list($student)]);
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        $this->authorizeStudent($request, $student);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $this->assertNameFree($student, $data['name']);

        $criterion = BehaviorStudentCriterion::create([
            'student_id' => $student->id, 'name' => trim($data['name']), 'created_by' => $request->user()->id,
            'sort_order' => (int) BehaviorStudentCriterion::where('student_id', $student->id)->max('sort_order') + 1,
        ]);

        return response()->json(['data' => $this->present($criterion)], 201);
    }

    public function update(Request $request, BehaviorStudentCriterion $criterion): JsonResponse
    {
        $this->authorizeStudent($request, $criterion->student);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'is_active' => ['sometimes', 'boolean']]);
        if (isset($data['name'])) {
            $this->assertNameFree($criterion->student, $data['name'], $criterion->id);
            $data['name'] = trim($data['name']);
        }
        $criterion->update($data);

        return response()->json(['data' => $this->present($criterion)]);
    }

    /** One already used in an evaluation keeps its history: it is hidden instead of removed. */
    public function destroy(Request $request, BehaviorStudentCriterion $criterion): Response
    {
        $this->authorizeStudent($request, $criterion->student);
        if (DB::table('behavior_special_scores')->where('behavior_student_criterion_id', $criterion->id)->exists()) {
            $criterion->update(['is_active' => false]);
        } else {
            $criterion->delete();
        }

        return response()->noContent();
    }

    private function authorizeStudent(Request $request, Student $student): void
    {
        abort_unless($this->access->canEvaluate($request->user(), $student), 403);
    }

    private function assertNameFree(Student $student, string $name, ?int $ignore = null): void
    {
        $taken = BehaviorStudentCriterion::where('student_id', $student->id)->where('is_active', true)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))
            ->get()->contains(fn ($c) => mb_strtolower($c->name) === mb_strtolower(trim($name)));
        if ($taken) {
            throw ValidationException::withMessages(['name' => [__('teacher_tools.special_name_taken')]]);
        }
    }

    /** @return list<array<string, mixed>> the student's active special criteria */
    private function list(Student $student): array
    {
        return BehaviorStudentCriterion::where('student_id', $student->id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($c) => $this->present($c))->all();
    }

    /** @return array<string, mixed> */
    private function present(BehaviorStudentCriterion $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'is_active' => $c->is_active];
    }
}
