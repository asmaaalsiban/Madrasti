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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A student makes a quiz for themselves from the bank, answers one question at a time and learns from the mistakes.
 * Only the student's own grade is ever used (decided here on the server). A question reaches the student without its
 * answer; the right option and the explanation come back only after the student answered it wrong.
 *
 * A quiz can have a timer, can be made of the questions the student got wrong, and can be taken again.
 */
class QuizController extends Controller
{
    public function __construct(private readonly BankAccess $access) {}

    /** The subjects of the student's grade, with the lessons that have questions, and how many mistakes the student still has. */
    public function options(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $grade = $this->gradeOf($student);
        $lessons = QuestionLesson::where('grade_level_id', $grade)->withCount(['questions as active' => fn ($q) => $q->where('is_active', true)])->get()->filter(fn ($l) => $l->active > 0);
        $mistakes = $this->mistakeIds($student, $grade, null);
        $bySubject = Question::whereIn('id', $mistakes)->with('lesson:id,subject_id')->get()->groupBy(fn ($q) => $q->lesson->subject_id)->map->count();
        $subjects = Subject::whereIn('id', $lessons->pluck('subject_id')->unique()->merge($bySubject->keys()))->orderBy('name_ar')->get()->map(fn (Subject $s) => [
            'id' => $s->id, 'name_ar' => $s->name_ar, 'name_en' => $s->name_en, 'mistakes' => (int) ($bySubject[$s->id] ?? 0),
            'lessons' => $lessons->where('subject_id', $s->id)->map(fn ($l) => ['id' => $l->id, 'title' => $l->title, 'questions' => $l->active])->values(),
        ]);

        return response()->json(['data' => $subjects->values(), 'mistakes' => $mistakes->count()]);
    }

    public function store(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $grade = $this->gradeOf($student);
        $d = $request->validate([
            'mode' => ['nullable', 'in:practice,mistakes'], 'subject_id' => ['nullable', 'integer'], 'lesson_ids' => ['nullable', 'array', 'max:50'], 'lesson_ids.*' => ['integer'],
            'count' => ['nullable', 'integer', 'min:1', 'max:100'], 'minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
        ]);
        $mode = $d['mode'] ?? 'practice';
        if ($mode === 'practice') {
            $request->validate(['subject_id' => ['required'], 'lesson_ids' => ['required', 'min:1'], 'count' => ['required']]);
            // Lessons of another grade or subject are simply not found: a student can never reach them.
            $lessonIds = QuestionLesson::whereIn('id', $d['lesson_ids'])->where('grade_level_id', $grade)->where('subject_id', $d['subject_id'])->pluck('id');
            $pool = Question::whereIn('question_lesson_id', $lessonIds)->where('is_active', true)->pluck('id');
        } else {
            $pool = $this->mistakeIds($student, $grade, $d['subject_id'] ?? null);
        }
        if ($pool->isEmpty()) {
            throw ValidationException::withMessages(['lesson_ids' => [__($mode === 'mistakes' ? 'questions.no_mistakes' : 'questions.no_questions')]]);
        }
        $picked = $pool->shuffle()->take($d['count'] ?? 100)->values();
        $subjectId = $d['subject_id'] ?? Question::with('lesson')->find($picked->first())->lesson->subject_id;
        $quiz = $this->make($student, (int) $subjectId, $picked, $mode, $d['minutes'] ?? null, null);

        return response()->json(['data' => $this->present($quiz), 'available' => $pool->count(), 'reduced' => isset($d['count']) && $picked->count() < $d['count']], 201);
    }

    /** The same questions again, in a fresh quiz (with the same timer, if it had one). */
    public function retake(Request $request, Quiz $quiz): JsonResponse
    {
        $student = $this->student($request);
        $this->own($request, $quiz);
        $this->expire($quiz);
        abort_if($quiz->finished_at === null, 422, __('questions.finish_first'));
        $ids = Question::whereIn('id', $quiz->items()->pluck('question_id'))->where('is_active', true)->pluck('id');
        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['quiz' => [__('questions.no_questions')]]);
        }
        $new = $this->make($student, $quiz->subject_id, $ids->shuffle()->values(), 'retake', $quiz->time_limit_minutes, $quiz->id);

        return response()->json(['data' => $this->present($new)], 201);
    }

    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        $this->own($request, $quiz);
        $this->expire($quiz);

        return response()->json(['data' => $this->present($quiz)]);
    }

    public function answer(Request $request, Quiz $quiz): JsonResponse
    {
        $this->own($request, $quiz);
        $this->expire($quiz);
        if ($quiz->finished_at !== null && $quiz->items()->whereNull('answered_at')->exists()) {
            throw ValidationException::withMessages(['quiz' => [__('questions.time_up')]]);
        }
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

            return $chosen->is_correct ? ['correct' => true] : ['correct' => false, 'correct_option_id' => $question->options->firstWhere('is_correct', true)?->id, 'explanation' => $question->explanation, 'has_explanation_image' => $question->explanation_image_path !== null];
        });

        return response()->json($result + ['finished' => $quiz->refresh()->finished_at !== null, 'score' => $quiz->finished_at ? $this->score($quiz) : null]);
    }

    /** A picture of a question (or, after a wrong answer, of its explanation). It is sent only to the student who has the question in their quiz. */
    public function image(Request $request, Quiz $quiz, QuizQuestion $item, string $slot): StreamedResponse
    {
        $this->own($request, $quiz);
        abort_unless($item->quiz_id === $quiz->id && in_array($slot, ['question', 'explanation'], true), 404);
        $question = $item->question;
        if ($slot === 'explanation') {
            abort_unless($item->answered_at !== null && $item->is_correct === false, 403);
        }
        $path = $slot === 'question' ? $question->image_path : $question->explanation_image_path;
        abort_unless($path !== null && Storage::exists($path), 404);

        return Storage::response($path);
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

    /** @param Collection<int, int> $questionIds */
    private function make(Student $student, int $subjectId, Collection $questionIds, string $kind, ?int $minutes, ?int $source): Quiz
    {
        return DB::transaction(function () use ($student, $subjectId, $questionIds, $kind, $minutes, $source): Quiz {
            $quiz = Quiz::create(['student_id' => $student->id, 'subject_id' => $subjectId, 'question_count' => $questionIds->count(), 'kind' => $kind, 'time_limit_minutes' => $minutes, 'deadline_at' => $minutes ? now()->addMinutes($minutes) : null, 'source_quiz_id' => $source]);
            foreach ($questionIds as $i => $questionId) {
                QuizQuestion::create(['quiz_id' => $quiz->id, 'question_id' => $questionId, 'position' => $i + 1]);
            }

            return $quiz;
        });
    }

    /**
     * The questions the student still gets wrong: the LAST time they answered each one it was wrong. Answering it right in a later quiz clears it.
     *
     * @return Collection<int, int>
     */
    private function mistakeIds(Student $student, int $grade, ?int $subjectId): Collection
    {
        $last = DB::table('quiz_questions as qq')->join('quizzes as z', 'z.id', '=', 'qq.quiz_id')->where('z.student_id', $student->id)->whereNotNull('qq.answered_at')
            ->orderBy('qq.answered_at')->orderBy('qq.id')->get(['qq.question_id', 'qq.is_correct'])->groupBy('question_id')->map(fn ($rows) => $rows->last());
        $wrong = $last->filter(fn ($r) => ! (bool) $r->is_correct)->keys();

        return Question::whereIn('id', $wrong)->where('is_active', true)
            ->whereHas('lesson', fn ($q) => $q->where('grade_level_id', $grade)->when($subjectId, fn ($q2) => $q2->where('subject_id', $subjectId)))->pluck('id');
    }

    /** A quiz whose time ran out is closed at its deadline: what was not answered stays unanswered (it does not count as right). */
    private function expire(Quiz $quiz): void
    {
        if ($quiz->finished_at === null && $quiz->deadline_at !== null && $quiz->deadline_at->isPast()) {
            $quiz->update(['finished_at' => $quiz->deadline_at]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function history(Student $student): array
    {
        return Quiz::with('items')->where('student_id', $student->id)->orderByDesc('id')->limit(100)->get()->map(function (Quiz $q): array {
            $this->expire($q);
            $subject = Subject::find($q->subject_id);

            return ['id' => $q->id, 'date' => $q->created_at->toDateString(), 'subject_ar' => $subject?->name_ar, 'subject_en' => $subject?->name_en, 'finished' => $q->finished_at !== null, 'kind' => $q->kind, 'timed' => $q->time_limit_minutes !== null] + $this->score($q);
        })->all();
    }

    /** @return array{answered: int, correct: int, wrong: int, total: int} */
    private function score(Quiz $quiz): array
    {
        $items = $quiz->items()->get();

        return ['answered' => $items->whereNotNull('answered_at')->count(), 'correct' => $items->where('is_correct', true)->count(), 'wrong' => $items->where('is_correct', false)->count(), 'total' => $items->count()];
    }

    /** @return array<string, mixed> */
    private function present(Quiz $quiz): array
    {
        $items = $quiz->items()->with('question')->get();
        $left = $quiz->deadline_at !== null && $quiz->finished_at === null ? max(0, (int) now()->diffInSeconds($quiz->deadline_at, false)) : null;

        return [
            'id' => $quiz->id, 'finished' => $quiz->finished_at !== null, 'kind' => $quiz->kind, 'minutes' => $quiz->time_limit_minutes, 'seconds_left' => $left,
            'timed_out' => $quiz->finished_at !== null && $quiz->time_limit_minutes !== null && $items->whereNull('answered_at')->isNotEmpty(),
            'questions' => $items->map(function (QuizQuestion $i): array {
                $q = $i->question;
                $answered = $i->answered_at !== null;
                $row = ['id' => $i->id, 'position' => $i->position, 'body' => $q->body, 'has_image' => $q->image_path !== null, 'answered' => $answered, 'option_id' => $i->option_id, 'is_correct' => $i->is_correct,
                    'options' => $q->options->sortBy(fn ($o) => crc32($i->id.'-'.$o->id))->map(fn ($o) => ['id' => $o->id, 'body' => $o->body])->values()];
                if ($answered && $i->is_correct === false) {
                    $row['correct_option_id'] = $q->options->firstWhere('is_correct', true)?->id;
                    $row['explanation'] = $q->explanation;
                    $row['has_explanation_image'] = $q->explanation_image_path !== null;
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
