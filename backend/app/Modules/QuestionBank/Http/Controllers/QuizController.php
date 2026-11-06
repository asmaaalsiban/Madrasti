<?php

declare(strict_types=1);

namespace App\Modules\QuestionBank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\QuestionBank\Models\Question;
use App\Modules\QuestionBank\Models\QuestionLesson;
use App\Modules\QuestionBank\Models\Quiz;
use App\Modules\QuestionBank\Models\QuizQuestion;
use App\Modules\QuestionBank\Services\BankAccess;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A student makes a quiz for themselves from the bank, answers one question at a time and learns from the mistakes.
 * Only the student's own grade is ever used (decided here on the server). A question reaches the student without its
 * answer; the right option and the explanation come back only after the student answered it wrong.
 */
class QuizController extends Controller
{
    public function __construct(private readonly BankAccess $access) {}

    /** The subjects of the student's grade, with the lessons that have questions. */
    public function options(Request $request): JsonResponse
    {
        $grade = $this->gradeOf($this->student($request));
        $lessons = QuestionLesson::where('grade_level_id', $grade)->withCount(['questions as active' => fn ($q) => $q->where('is_active', true)])->get()->filter(fn ($l) => $l->active > 0);
        $subjects = Subject::whereIn('id', $lessons->pluck('subject_id')->unique())->orderBy('name_ar')->get()->map(fn (Subject $s) => [
            'id' => $s->id, 'name_ar' => $s->name_ar, 'name_en' => $s->name_en,
            'lessons' => $lessons->where('subject_id', $s->id)->map(fn ($l) => ['id' => $l->id, 'title' => $l->title, 'questions' => $l->active])->values(),
        ]);

        return response()->json(['data' => $subjects->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $grade = $this->gradeOf($student);
        $d = $request->validate(['subject_id' => ['required', 'integer'], 'lesson_ids' => ['required', 'array', 'min:1', 'max:50'], 'lesson_ids.*' => ['integer'], 'count' => ['required', 'integer', 'min:1', 'max:100']]);
        // Lessons of another grade or subject are simply not found: a student can never reach them.
        $lessonIds = QuestionLesson::whereIn('id', $d['lesson_ids'])->where('grade_level_id', $grade)->where('subject_id', $d['subject_id'])->pluck('id');
        $pool = Question::whereIn('question_lesson_id', $lessonIds)->where('is_active', true)->pluck('id');
        if ($pool->isEmpty()) {
            throw ValidationException::withMessages(['lesson_ids' => [__('questions.no_questions')]]);
        }
        $picked = $pool->shuffle()->take($d['count'])->values();

        $quiz = DB::transaction(function () use ($student, $d, $picked): Quiz {
            $quiz = Quiz::create(['student_id' => $student->id, 'subject_id' => $d['subject_id'], 'question_count' => $picked->count()]);
            foreach ($picked as $i => $questionId) {
                QuizQuestion::create(['quiz_id' => $quiz->id, 'question_id' => $questionId, 'position' => $i + 1]);
            }

            return $quiz;
        });

        return response()->json(['data' => $this->present($quiz), 'available' => $pool->count(), 'reduced' => $picked->count() < $d['count']], 201);
    }

    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        $this->own($request, $quiz);

        return response()->json(['data' => $this->present($quiz)]);
    }

    public function answer(Request $request, Quiz $quiz): JsonResponse
    {
        $this->own($request, $quiz);
        $d = $request->validate(['quiz_question_id' => ['required', 'integer'], 'option_id' => ['required', 'integer']]);
        $result = DB::transaction(function () use ($quiz, $d): array {
            $item = QuizQuestion::where('quiz_id', $quiz->id)->whereKey($d['quiz_question_id'])->lockForUpdate()->first();
            abort_if($item === null, 404);
            if ($item->answered_at !== null) {
                throw ValidationException::withMessages(['quiz_question_id' => [__('questions.already_answered')]]);
            }
            $question = $item->question;
            $chosen = $question->options->firstWhere('id', $d['option_id']);
            if ($chosen === null) {
                throw ValidationException::withMessages(['option_id' => [__('questions.bad_option')]]);
            }
            $item->update(['option_id' => $chosen->id, 'is_correct' => $chosen->is_correct, 'answered_at' => now()]);
            if (! QuizQuestion::where('quiz_id', $quiz->id)->whereNull('answered_at')->exists()) {
                $quiz->update(['finished_at' => now()]);
            }

            return $chosen->is_correct ? ['correct' => true] : ['correct' => false, 'correct_option_id' => $question->options->firstWhere('is_correct', true)?->id, 'explanation' => $question->explanation];
        });

        return response()->json($result + ['finished' => $quiz->refresh()->finished_at !== null, 'score' => $quiz->finished_at ? $this->score($quiz) : null]);
    }

    /** The student's own quizzes, newest first. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->history($this->student($request))]);
    }

    /** The same history for a student's parents and the admin (a gate checks). */
    public function forStudent(Student $student): JsonResponse
    {
        Gate::authorize('view-student-homework', $student);

        return response()->json(['data' => $this->history($student)]);
    }

    /** @return list<array<string, mixed>> */
    private function history(Student $student): array
    {
        return Quiz::with('items')->where('student_id', $student->id)->orderByDesc('id')->limit(100)->get()->map(function (Quiz $q): array {
            $subject = Subject::find($q->subject_id);

            return ['id' => $q->id, 'date' => $q->created_at->toDateString(), 'subject_ar' => $subject?->name_ar, 'subject_en' => $subject?->name_en, 'finished' => $q->finished_at !== null] + $this->score($q);
        })->all();
    }

    /** @return array{answered: int, correct: int, total: int} */
    private function score(Quiz $quiz): array
    {
        $items = $quiz->items()->get();

        return ['answered' => $items->whereNotNull('answered_at')->count(), 'correct' => $items->where('is_correct', true)->count(), 'total' => $items->count()];
    }

    /** @return array<string, mixed> */
    private function present(Quiz $quiz): array
    {
        $items = $quiz->items()->with('question')->get();

        return [
            'id' => $quiz->id, 'finished' => $quiz->finished_at !== null,
            'questions' => $items->map(function (QuizQuestion $i): array {
                $q = $i->question;
                $answered = $i->answered_at !== null;
                $row = ['id' => $i->id, 'position' => $i->position, 'body' => $q->body, 'answered' => $answered, 'option_id' => $i->option_id, 'is_correct' => $i->is_correct,
                    'options' => $q->options->sortBy(fn ($o) => crc32($i->id.'-'.$o->id))->map(fn ($o) => ['id' => $o->id, 'body' => $o->body])->values()];
                if ($answered && $i->is_correct === false) {
                    $row['correct_option_id'] = $q->options->firstWhere('is_correct', true)?->id;
                    $row['explanation'] = $q->explanation;
                }

                return $row;
            })->values(),
        ] + $this->score($quiz);
    }

    private function student(Request $request): Student
    {
        $user = $request->user();
        abort_unless($user->hasRole(Role::Student) && $user->student !== null, 403, __('questions.students_only'));

        return $user->student;
    }

    private function gradeOf(Student $student): int
    {
        $grade = $this->access->gradeOf($student);
        abort_if($grade === null, 422, __('questions.no_grade'));

        return $grade;
    }

    private function own(Request $request, Quiz $quiz): void
    {
        abort_unless($quiz->student_id === $this->student($request)->id, 403);
    }
}
