<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Services;

use App\Modules\Academic\Models\Term;
use App\Modules\TeacherTools\Models\BehaviorEvaluation;
use Illuminate\Support\Collection;

/**
 * Builds the weekly, monthly and term results of one student, each from the one below it:
 * a week is the mean of the teachers' evaluations of that week, a month the mean of its weeks, a term the mean of its months.
 * A result is a score per criterion (1-5) and an overall mean; empty criteria are left out of the means.
 */
class BehaviorSummary
{
    /**
     * @param  Collection<int, BehaviorEvaluation>  $evaluations  with scores
     * @param  Collection<int, Term>  $terms
     * @return array{weeks: list<array<string, mixed>>, months: list<array<string, mixed>>, terms: list<array<string, mixed>>}
     */
    public function build(Collection $evaluations, Collection $terms): array
    {
        $weeks = $evaluations->groupBy(fn (BehaviorEvaluation $e) => $e->week_starts_on->format('Y-m-d'))->map(function (Collection $group, string $week): array {
            return ['week_starts_on' => $week, 'evaluations' => $group->count(), 'criteria' => $this->mean($group->map(fn (BehaviorEvaluation $e) => $e->scores->pluck('score', 'behavior_criterion_id')->all()))];
        })->sortKeys();

        $months = $weeks->groupBy(fn (array $w) => substr($w['week_starts_on'], 0, 7))->map(fn (Collection $group, string $month): array => [
            'month' => $month, 'weeks' => $group->count(), 'criteria' => $this->mean($group->map(fn (array $w) => $w['criteria'])),
        ])->sortKeys();

        $termResults = $terms->map(function (Term $term) use ($weeks): array {
            $inTerm = $weeks->filter(fn (array $w) => $w['week_starts_on'] >= $term->starts_on->format('Y-m-d') && $w['week_starts_on'] <= $term->ends_on->format('Y-m-d'));
            $months = $inTerm->groupBy(fn (array $w) => substr($w['week_starts_on'], 0, 7))->map(fn (Collection $g) => $this->mean($g->map(fn (array $w) => $w['criteria'])));

            return ['term' => ['id' => $term->id, 'name' => $term->name], 'months' => $months->count(), 'weeks' => $inTerm->count(), 'criteria' => $this->mean($months)];
        })->filter(fn (array $t) => $t['weeks'] > 0)->values();

        return [
            'weeks' => $weeks->map(fn (array $w) => $w + ['overall' => $this->overall($w['criteria'])])->values()->all(),
            'months' => $months->map(fn (array $m) => $m + ['overall' => $this->overall($m['criteria'])])->values()->all(),
            'terms' => $termResults->map(fn (array $t) => $t + ['overall' => $this->overall($t['criteria'])])->all(),
        ];
    }

    /**
     * @param  Collection<int|string, array<int|string, int|float>>  $rows  criterion id => score
     * @return array<int|string, float>
     */
    private function mean(Collection $rows): array
    {
        $out = [];
        foreach ($rows->flatMap(fn (array $r) => array_keys($r))->unique() as $id) {
            $values = $rows->filter(fn (array $r) => isset($r[$id]))->map(fn (array $r) => $r[$id]);
            $out[$id] = round($values->avg(), 2);
        }
        ksort($out);

        return $out;
    }

    /** @param  array<int|string, float>  $criteria */
    private function overall(array $criteria): ?float
    {
        return $criteria === [] ? null : round(array_sum($criteria) / count($criteria), 2);
    }
}
