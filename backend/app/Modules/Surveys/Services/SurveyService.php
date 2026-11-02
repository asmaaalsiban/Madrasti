<?php

declare(strict_types=1);

namespace App\Modules\Surveys\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyQuestion;
use App\Modules\Surveys\Models\SurveyRecipient;
use App\Modules\Surveys\Models\SurveyResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creates a survey with the parents who must answer it, tells them, and checks and stores their answers. */
class SurveyService
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @param  array<string, mixed>  $data  validated
     * @param  list<array<string, mixed>>  $questions
     */
    public function create(User $author, array $data, array $questions): Survey
    {
        $survey = DB::transaction(function () use ($author, $data, $questions): Survey {
            $survey = Survey::create([
                'created_by' => $author->id, 'type' => $data['type'], 'title' => $data['title'], 'description' => $data['description'] ?? null,
                'closes_at' => $data['closes_at'] ?? null, 'audience' => $data['audience'], 'classroom_id' => $data['audience'] === 'classroom' ? $data['classroom_id'] : null,
            ]);
            foreach (array_values($questions) as $i => $q) {
                $survey->questions()->create([
                    'type' => $q['type'], 'text' => $q['text'], 'is_required' => $q['is_required'] ?? true, 'sort_order' => $i,
                    'options' => in_array($q['type'], ['single_choice', 'multiple_choice'], true) ? array_values(array_map('trim', $q['options'])) : null,
                ]);
            }
            foreach ($this->recipientRows($survey) as $row) {
                SurveyRecipient::create($row + ['survey_id' => $survey->id]);
            }

            return $survey;
        });

        $this->notifier->toUsers($survey->recipients()->pluck('user_id'), 'survey_new', ['title' => $survey->title, 'type' => $survey->type], '/parent/surveys');

        return $survey;
    }

    /**
     * The parents of the audience: an approval is per child (one row for each parent and child), a poll or survey per parent.
     *
     * @return list<array{user_id: int, student_id: ?int}>
     */
    private function recipientRows(Survey $survey): array
    {
        $year = AcademicYear::where('is_current', true)->first();
        $studentIds = Enrollment::where('academic_year_id', $year?->id)->when($survey->audience === 'classroom', fn ($q) => $q->where('classroom_id', $survey->classroom_id))->pluck('student_id');

        $rows = [];
        foreach (Student::with('guardians:users.id')->whereIn('id', $studentIds)->get() as $student) {
            foreach ($student->guardians as $guardian) {
                $perChild = $survey->type === 'approval';
                $rows["{$guardian->id}-".($perChild ? $student->id : 0)] = ['user_id' => $guardian->id, 'student_id' => $perChild ? $student->id : null];
            }
        }

        return array_values($rows);
    }

    /** Tells the parents who have not answered yet (at most once every 6 hours). Returns how many were told. */
    public function remind(Survey $survey): int
    {
        abort_unless($survey->isOpen(), 422);
        if ($survey->reminded_at?->gt(now()->subHours(6))) {
            throw ValidationException::withMessages(['survey' => [__('surveys.remind_too_soon')]]);
        }
        $pending = $survey->recipients()->whereNotExists(function ($q): void {
            $q->from('survey_responses')->whereColumn('survey_responses.survey_id', 'survey_recipients.survey_id')->whereColumn('survey_responses.user_id', 'survey_recipients.user_id')
                ->where(fn ($w) => $w->whereColumn('survey_responses.student_id', 'survey_recipients.student_id')->orWhere(fn ($n) => $n->whereNull('survey_responses.student_id')->whereNull('survey_recipients.student_id')));
        })->pluck('user_id')->unique();
        $this->notifier->toUsers($pending, 'survey_reminder', ['title' => $survey->title, 'type' => $survey->type], '/parent/surveys');
        $survey->update(['reminded_at' => now()]);

        return $pending->count();
    }

    /**
     * Stores a parent's answer (replacing an earlier one while the survey is open).
     *
     * @param  array<string, mixed>  $answers  question id => value
     */
    public function respond(SurveyRecipient $recipient, ?string $decision, array $answers): SurveyResponse
    {
        $survey = $recipient->survey()->with('questions')->firstOrFail();
        if (! $survey->isOpen()) {
            throw ValidationException::withMessages(['survey' => [__('surveys.closed')]]);
        }
        if ($survey->type === 'approval' && ! in_array($decision, ['approved', 'declined'], true)) {
            throw ValidationException::withMessages(['decision' => [__('surveys.decision_required')]]);
        }

        // A parent who declines an approval does not have to fill in the other questions.
        $skipRequired = $survey->type === 'approval' && $decision === 'declined';
        $clean = [];
        $errors = [];
        foreach ($survey->questions as $q) {
            $value = $answers[$q->id] ?? $answers[(string) $q->id] ?? null;
            $empty = $value === null || $value === '' || $value === [];
            if ($empty) {
                if ($q->is_required && ! $skipRequired) {
                    $errors["answers.{$q->id}"] = [__('surveys.answer_required')];
                }

                continue;
            }
            $checked = $this->checked($q, $value);
            if ($checked === null) {
                $errors["answers.{$q->id}"] = [__('surveys.answer_invalid')];
            } else {
                $clean[$q->id] = $checked;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($recipient, $survey, $decision, $clean): SurveyResponse {
            $response = SurveyResponse::updateOrCreate(
                ['survey_id' => $survey->id, 'user_id' => $recipient->user_id, 'student_id' => $recipient->student_id],
                ['decision' => $survey->type === 'approval' ? $decision : null, 'submitted_at' => now()],
            );
            $response->answers()->delete();
            foreach ($clean as $questionId => $value) {
                $response->answers()->create(['survey_question_id' => $questionId, 'value' => $value]);
            }

            return $response;
        });
    }

    /** The answer in its stored form, or null when it does not fit the question. */
    private function checked(SurveyQuestion $q, mixed $value): mixed
    {
        return match ($q->type) {
            'text' => is_string($value) && mb_strlen($value) <= 1000 ? trim($value) : null,
            'yes_no' => is_bool($value) ? $value : (in_array($value, ['yes', 'no'], true) ? $value === 'yes' : null),
            'single_choice' => is_string($value) && in_array($value, $q->options ?? [], true) ? $value : null,
            'multiple_choice' => is_array($value) && array_diff($value, $q->options ?? []) === [] && count($value) === count(array_unique($value)) ? array_values($value) : null,
            default => null,
        };
    }
}
