<?php

declare(strict_types=1);

namespace App\Modules\Grading\Services;

use App\Modules\Academic\Models\Enrollment;
use App\Modules\Grading\Models\Assessment;
use App\Modules\Grading\Models\AssessmentScore;
use Illuminate\Support\Collection;

/**
 * The result of every student of a classroom in one subject and term.
 *
 * percent = sum(score / max * weight) / sum(weight) * 100, over the assessments the student was graded in (an absent
 * student waiting for a makeup exam is "pending": that assessment is left out, not counted as zero).
 * passed = percent >= config('grading.pass_percent'); null while nothing is graded.
 */
class SubjectResults
{
    /**
     * @return Collection<int, array{student: array<string, mixed>, percent: ?float, passed: ?bool, graded: int, pending: int}>
     */
    public function forClassroom(int $classroomId, int $subjectId, int $termId, bool $publishedOnly = false): Collection
    {
        $assessments = Assessment::where('classroom_id', $classroomId)->where('subject_id', $subjectId)->where('term_id', $termId)
            ->when($publishedOnly, fn ($q) => $q->where('is_published', true))->get()->keyBy('id');

        $scores = AssessmentScore::whereIn('assessment_id', $assessments->keys())->get()->groupBy('student_id');

        $students = Enrollment::where('classroom_id', $classroomId)
            ->join('students', 'students.id', '=', 'enrollments.student_id')->whereNull('students.deleted_at')
            ->orderBy('students.name_ar')->get(['students.id', 'students.student_number', 'students.name_ar', 'students.name_en']);

        return $students->map(fn ($student) => ['student' => $student->only(['id', 'student_number', 'name_ar', 'name_en'])] + $this->summarize($assessments, $scores->get($student->id, collect())));
    }

    /**
     * @param  Collection<int, Assessment>  $assessments
     * @param  Collection<int, AssessmentScore>  $scores
     * @return array{percent: ?float, passed: ?bool, graded: int, pending: int}
     */
    public function summarize(Collection $assessments, Collection $scores): array
    {
        $weighted = 0.0;
        $weights = 0.0;
        $ratios = [];
        $pending = 0;

        foreach ($scores as $score) {
            $assessment = $assessments->get($score->assessment_id);
            if ($assessment === null) {
                continue;
            }
            if ($score->status === 'absent' && $score->score === null) {
                $pending++;

                continue;
            }
            if ($score->score === null) {
                continue;
            }
            $ratio = $score->score / $assessment->max_score;
            $ratios[] = $ratio;
            $weighted += $ratio * $assessment->weight;
            $weights += $assessment->weight;
        }

        $percent = match (true) {
            $ratios === [] => null,
            $weights > 0 => round($weighted / $weights * 100, 1),
            default => round(array_sum($ratios) / count($ratios) * 100, 1), // every weight is 0: plain average
        };

        return [
            'percent' => $percent,
            'passed' => $percent === null ? null : $percent >= (float) config('grading.pass_percent'),
            'graded' => count($ratios),
            'pending' => $pending,
        ];
    }
}
