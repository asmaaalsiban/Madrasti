<?php

declare(strict_types=1);

namespace App\Modules\QuestionBank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Modules\QuestionBank\Models\Question;
use App\Modules\QuestionBank\Models\QuestionLesson;
use App\Modules\QuestionBank\Models\QuizQuestion;
use App\Modules\QuestionBank\Services\BankAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\ValidationException;

/** Teachers (and the admin) write lessons and multiple-choice questions for the grades and subjects they teach. */
class QuestionAuthoringController extends Controller
{
    public function __construct(private readonly BankAccess $access) {}

    /** The grades and subjects the user may write for. */
    public function scope(Request $request): JsonResponse
    {
        $pairs = collect($this->access->pairs($request->user()));
        $grades = GradeLevel::whereIn('id', $pairs->pluck('grade_level_id'))->get()->keyBy('id');
        $subjects = Subject::whereIn('id', $pairs->pluck('subject_id'))->get()->keyBy('id');
        $rows = $pairs->map(fn ($p) => [
            'grade_level_id' => $p['grade_level_id'], 'grade_ar' => $grades[$p['grade_level_id']]->name_ar, 'grade_en' => $grades[$p['grade_level_id']]->name_en,
            'subject_id' => $p['subject_id'], 'subject_ar' => $subjects[$p['subject_id']]->name_ar, 'subject_en' => $subjects[$p['subject_id']]->name_en,
        ])->sortBy([['grade_level_id', 'asc'], ['subject_id', 'asc']])->values();

        return response()->json(['data' => $rows]);
    }

    public function lessons(Request $request): JsonResponse
    {
        $d = $request->validate(['grade_level_id' => ['required', 'integer'], 'subject_id' => ['required', 'integer']]);
        $this->allow($request, (int) $d['grade_level_id'], (int) $d['subject_id']);
        $rows = QuestionLesson::withCount('questions')->where($d)->orderBy('id')->get()->map(fn ($l) => ['id' => $l->id, 'title' => $l->title, 'questions' => $l->questions_count]);

        return response()->json(['data' => $rows]);
    }

    public function storeLesson(Request $request): JsonResponse
    {
        $d = $request->validate(['grade_level_id' => ['required', 'integer', 'exists:grade_levels,id'], 'subject_id' => ['required', 'integer', 'exists:subjects,id'], 'title' => ['required', 'string', 'max:150']]);
        $this->allow($request, (int) $d['grade_level_id'], (int) $d['subject_id']);
        if (QuestionLesson::where($d)->exists()) {
            throw ValidationException::withMessages(['title' => [__('questions.lesson_exists')]]);
        }
        $lesson = QuestionLesson::create($d + ['created_by' => $request->user()->id]);

        return response()->json(['data' => ['id' => $lesson->id, 'title' => $lesson->title, 'questions' => 0]], 201);
    }

    public function updateLesson(Request $request, QuestionLesson $lesson): JsonResponse
    {
        $this->allow($request, (int) $lesson->grade_level_id, (int) $lesson->subject_id);
        $d = $request->validate(['title' => ['required', 'string', 'max:150']]);
        if (QuestionLesson::where('grade_level_id', $lesson->grade_level_id)->where('subject_id', $lesson->subject_id)->where('title', $d['title'])->where('id', '!=', $lesson->id)->exists()) {
            throw ValidationException::withMessages(['title' => [__('questions.lesson_exists')]]);
        }
        $lesson->update($d);

        return response()->json(['data' => ['id' => $lesson->id, 'title' => $lesson->title]]);
    }

    public function destroyLesson(Request $request, QuestionLesson $lesson): Response
    {
        $this->allow($request, (int) $lesson->grade_level_id, (int) $lesson->subject_id);
        if ($lesson->questions()->exists()) {
            throw ValidationException::withMessages(['lesson' => [__('questions.lesson_has_questions')]]);
        }
        $lesson->delete();

        return response()->noContent();
    }

    /** The questions of a lesson, with the answers (this is the teacher's view). */
    public function questions(Request $request, QuestionLesson $lesson): JsonResponse
    {
        $this->allow($request, (int) $lesson->grade_level_id, (int) $lesson->subject_id);
        $rows = $lesson->questions()->with('options')->orderBy('id')->get()->map(fn (Question $q) => $this->present($q));

        return response()->json(['data' => $rows]);
    }

    public function storeQuestion(Request $request, QuestionLesson $lesson): JsonResponse
    {
        $this->allow($request, (int) $lesson->grade_level_id, (int) $lesson->subject_id);
        $d = $this->data($request);
        $q = DB::transaction(function () use ($lesson, $d, $request): Question {
            $q = Question::create(['question_lesson_id' => $lesson->id, 'created_by' => $request->user()->id, 'body' => $d['body'], 'explanation' => $d['explanation'] ?? null]);
            $this->writeOptions($q, $d['options']);

            return $q;
        });

        return response()->json(['data' => $this->present($q->load('options'))], 201);
    }

    public function updateQuestion(Request $request, Question $question): JsonResponse
    {
        $lesson = $question->lesson;
        $this->allow($request, (int) $lesson->grade_level_id, (int) $lesson->subject_id);
        $d = $this->data($request);
        DB::transaction(function () use ($question, $d): void {
            $question->update(['body' => $d['body'], 'explanation' => $d['explanation'] ?? null]);
            if (QuizQuestion::where('question_id', $question->id)->exists()) { // students already answered it: keep the options they chose from, change only their wording
                $this->rewordOptions($question, $d['options']);
            } else {
                $question->options()->delete();
                $this->writeOptions($question, $d['options']);
            }
        });

        return response()->json(['data' => $this->present($question->refresh()->load('options'))]);
    }

    /** A question students already met is switched off instead of removed, so their results stay whole. */
    public function destroyQuestion(Request $request, Question $question): Response
    {
        $lesson = $question->lesson;
        $this->allow($request, (int) $lesson->grade_level_id, (int) $lesson->subject_id);
        if (QuizQuestion::where('question_id', $question->id)->exists()) {
            $question->update(['is_active' => false]);
        } else {
            array_map(fn (?string $p) => $p !== null && Storage::delete($p), [$question->image_path, $question->explanation_image_path]);
            $question->delete();
        }

        return response()->noContent();
    }

    /** A picture for the question itself or for its explanation (jpg, png or webp, up to 5 MB). It replaces the one it had. */
    public function setImage(Request $request, Question $question, string $slot): JsonResponse
    {
        $column = $this->column($request, $question, $slot);
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $old = $question->{$column};
        $question->update([$column => $request->file('image')->store('question-bank')]);
        if ($old !== null) {
            Storage::delete($old);
        }

        return response()->json(['data' => $this->present($question->refresh()->load('options'))]);
    }

    public function removeImage(Request $request, Question $question, string $slot): JsonResponse
    {
        $column = $this->column($request, $question, $slot);
        if ($question->{$column} !== null) {
            Storage::delete($question->{$column});
            $question->update([$column => null]);
        }

        return response()->json(['data' => $this->present($question->load('options'))]);
    }

    public function showImage(Request $request, Question $question, string $slot): StreamedResponse
    {
        $column = $this->column($request, $question, $slot);
        abort_unless($question->{$column} !== null && Storage::exists($question->{$column}), 404);

        return Storage::response($question->{$column});
    }

    private function column(Request $request, Question $question, string $slot): string
    {
        abort_unless(in_array($slot, ['question', 'explanation'], true), 404);
        $this->allow($request, (int) $question->lesson->grade_level_id, (int) $question->lesson->subject_id);

        return $slot === 'question' ? 'image_path' : 'explanation_image_path';
    }

    private function allow(Request $request, int $grade, int $subject): void
    {
        abort_unless($this->access->can($request->user(), $grade, $subject), 403, __('questions.not_allowed'));
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $d = $request->validate([
            'body' => ['required', 'string', 'max:2000'], 'explanation' => ['nullable', 'string', 'max:4000'],
            'options' => ['required', 'array', 'min:2', 'max:6'], 'options.*.body' => ['required', 'string', 'max:500'], 'options.*.is_correct' => ['required', 'boolean'],
        ]);
        if (collect($d['options'])->where('is_correct', true)->count() !== 1) {
            throw ValidationException::withMessages(['options' => [__('questions.one_correct')]]);
        }

        return $d;
    }

    /** @param list<array{body: string, is_correct: bool}> $options */
    private function writeOptions(Question $q, array $options): void
    {
        foreach (array_values($options) as $i => $o) {
            $q->options()->create(['body' => $o['body'], 'is_correct' => $o['is_correct'], 'position' => $i]);
        }
    }

    /** @param list<array{body: string, is_correct: bool}> $options */
    private function rewordOptions(Question $q, array $options): void
    {
        $existing = $q->options()->get();
        if ($existing->count() !== count($options)) {
            throw ValidationException::withMessages(['options' => [__('questions.options_locked')]]);
        }
        foreach (array_values($options) as $i => $o) {
            $existing[$i]->update(['body' => $o['body'], 'is_correct' => $o['is_correct']]);
        }
    }

    /** @return array<string, mixed> */
    private function present(Question $q): array
    {
        return ['id' => $q->id, 'body' => $q->body, 'explanation' => $q->explanation, 'is_active' => $q->is_active, 'has_image' => $q->image_path !== null, 'has_explanation_image' => $q->explanation_image_path !== null, 'options' => $q->options->map(fn ($o) => ['id' => $o->id, 'body' => $o->body, 'is_correct' => $o->is_correct])->values()];
    }
}
