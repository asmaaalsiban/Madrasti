<?php

declare(strict_types=1);

namespace App\Modules\Grading\Services;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Term;
use App\Modules\Grading\Models\Assessment;
use App\Modules\Grading\Models\AssessmentScore;

/** The published grades of one student for an academic year, by term and subject, with each subject's result. */
class StudentTranscript
{
    public function __construct(private readonly SubjectResults $results) {}

    /**
     * @return list<array{id: int, name: string, starts_on: string, ends_on: string, subjects: \Illuminate\Support\Collection<int, array<string, mixed>>}>
     */
    public function terms(Student $student, ?AcademicYear $year, ?Enrollment $enrollment): array
    {
        $results = $this->results;
        $terms = [];
        if ($year && $enrollment) {
            $assessments = Assessment::where('classroom_id', $enrollment->classroom_id)->where('is_published', true)
                ->whereHas('term', fn ($q) => $q->where('academic_year_id', $year->id))->with('subject')->orderBy('held_on')->orderBy('id')->get();
            $scores = AssessmentScore::where('student_id', $student->id)->whereIn('assessment_id', $assessments->pluck('id'))->get()->keyBy('assessment_id');

            foreach (Term::where('academic_year_id', $year->id)->orderBy('starts_on')->get() as $term) {
                $subjects = $assessments->where('term_id', $term->id)->groupBy('subject_id')->map(function ($group) use ($results, $scores): array {
                    $summary = $results->summarize($group->keyBy('id'), $group->map(fn ($a) => $scores->get($a->id))->filter()->values());

                    return [
                        'subject' => $group->first()->subject->only(['id', 'name_ar', 'name_en']),
                        'percent' => $summary['percent'],
                        'result' => match (true) {
                            $summary['retake'] > 0 => 'retake',
                            $summary['pending'] > 0 => 'makeup',
                            $summary['percent'] === null => 'none',
                            default => $summary['passed'] ? 'pass' : 'fail',
                        },
                        'assessments' => $group->map(function (Assessment $a) use ($scores): array {
                            $score = $scores->get($a->id);

                            return [
                                'id' => $a->id, 'title' => $a->title, 'type' => $a->type, 'max_score' => $a->max_score, 'weight' => $a->weight,
                                'held_on' => $a->held_on?->format('Y-m-d'),
                                'score' => $score?->score,
                                'first_score' => $score?->first_score,
                                'status' => $score?->status,
                                'percent' => $score?->score === null ? null : round($score->score / $a->max_score * 100, 1),
                            ];
                        })->values(),
                    ];
                })->sortBy(fn ($s) => $s['subject']['name_en'])->values();

                $terms[] = ['id' => $term->id, 'name' => $term->name, 'starts_on' => $term->starts_on->format('Y-m-d'), 'ends_on' => $term->ends_on->format('Y-m-d'), 'subjects' => $subjects];
            }
        }

        return $terms;
    }
}
