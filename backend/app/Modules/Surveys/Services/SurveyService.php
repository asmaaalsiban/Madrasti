<?php

declare(strict_types=1);

namespace App\Modules\Surveys\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyQuestion;
use App\Modules\Surveys\Models\SurveyRecipient;
use App\Shared\Enums\Role;
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
                'closes_at' => $data['closes_at'] ?? null, 'audience' => $data['audience'], 'respondents' => $data['type'] === 'approval' ? 'parents' : ($data['respondents'] ?? 'parents'),
                'grade_from_id' => $data['audience'] === 'grades' ? $data['grade_from_id'] : null, 'grade_to_id' => $data['audience'] === 'grades' ? $data['grade_to_id'] : null,
            ]);
            if ($data['audience'] === 'classrooms') {
                $survey->classrooms()->sync($data['classroom_ids']);
            }
            foreach (array_values($questions) as $i => $q) {
                $survey->questions()->create([
                    'type' => $q['type'], 'text' => $q['text'], 'is_required' => $q['is_required'] ?? true, 'sort_order' => $i,
                    'options' => in_array($q['type'], ['single_choice', 'multiple_choice'], true) ? array_values(array_map('trim', $q['options'])) : null,
                ]);
            }
            foreach ($this->recipientRows($survey, $data['staff_ids'] ?? []) as $row) {
                SurveyRecipient::create($row + ['survey_id' => $survey->id]);
            }

            return $survey;
        });

        $this->notify($survey, 'survey_new');

        return $survey;
    }

    /**
     * The parents of the audience: an approval is per child (one row for each parent and child), a poll or survey per parent.
     *
     * @param  list<int>  $staffIds
     * @return list<array{user_id: int, student_id: ?int}>
     */
    private function recipientRows(Survey $survey, array $staffIds = []): array
    {
        // A staff survey goes to the members of staff the admin picked, whatever the students or parents are.
        if ($survey->respondents === 'staff') {
            return array_map(fn ($id) => ['user_id' => (int) $id, 'student_id' => null], array_values(array_unique($staffIds)));
        }

        $year = AcademicYear::where('is_current', true)->first();
        $classroomIds = match ($survey->audience) {
            'classrooms' => $survey->classrooms()->pluck('classrooms.id'),
            // From the lower grade to the higher one, whichever order they were chosen in.
            'grades' => Classroom::where('academic_year_id', $year?->id)->whereIn('grade_level_id', GradeLevel::whereBetween('sort_order', [
                min($survey->gradeFrom->sort_order, $survey->gradeTo->sort_order), max($survey->gradeFrom->sort_order, $survey->gradeTo->sort_order),
            ])->select('id'))->pluck('id'),
            default => null, // everyone
        };
        $studentIds = Enrollment::where('academic_year_id', $year?->id)->when($classroomIds !== null, fn ($q) => $q->whereIn('classroom_id', $classroomIds))->pluck('student_id');

        $rows = [];
        foreach (Student::with('guardians:users.id')->whereIn('id', $studentIds)->get() as $student) {
            if ($survey->respondents !== 'students') {
                foreach ($student->guardians as $guardian) {
                    $perChild = $survey->type === 'approval';
                    $rows["{$guardian->id}-".($perChild ? $student->id : 0)] = ['user_id' => $guardian->id, 'student_id' => $perChild ? $student->id : null];
                }
            }
            // The student answers for themselves (never an approval: that is the parents').
            if ($survey->respondents !== 'parents' && $student->user_id !== null) {
                $rows["{$student->user_id}-{$student->id}"] = ['user_id' => $student->user_id, 'student_id' => $student->id];
            }
        }

        return array_values($rows);
    }

    /** Tells the people who have to answer (or, for a reminder, those who have not yet): parents and students each get the link to their own page. */
    private function notify(Survey $survey, string $kind, ?\Illuminate\Support\Collection $userIds = null): void
    {
        $ids = $userIds ?? $survey->recipients()->pluck('user_id')->unique();
        $params = ['title' => $survey->title, 'type' => $survey->type];
        // Everyone gets the link to their own page: students, teachers and admin-side staff each have theirs; parents the default.
        $links = ['student' => '/student/surveys', 'teacher' => '/teacher/my-surveys', 'admin' => '/admin/my-surveys'];
        $roles = User::whereIn('id', $ids)->pluck('role', 'id')->map(fn ($role) => $role->value);
        foreach ($links as $role => $link) {
            $this->notifier->toUsers($roles->filter(fn ($r) => $r === $role)->keys(), $kind, $params, $link);
        }
        // Everyone else is a parent.
        $this->notifier->toUsers($roles->reject(fn ($r) => isset($links[$r]))->keys(), $kind, $params, '/parent/surveys');
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
        $this->notify($survey, 'survey_reminder', $pending);
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
