<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\Finance\Models\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How committed a staff member was in a month, from what the system already records: arriving on time, writing the daily
 * summary of each scheduled lesson, and the weekly lesson plan of each class they teach. Each measure is a score of 0 to 100
 * (null when it does not apply, e.g. no lessons on the timetable); the rules turn a score into a bonus or a deduction.
 */
class CommitmentService
{
    public function __construct(private readonly StaffAttendanceService $attendance, private readonly CommitmentRules $rules) {}

    /**
     * @return array<string, array{score: int|null, done: int, expected: int, absent?: int, absent_dates?: list<string>}>
     */
    public function scores(User $user, Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->min(now()->startOfDay());
        $days = $this->workdays($from, $to);

        return [
            'punctuality' => $this->punctuality($user, $days),
            'daily_summary' => $this->dailySummaries($user, $days),
            'weekly_plan' => $this->weeklyPlans($user, $from, $to),
        ];
    }

    /**
     * The bonus or deduction each enabled rule gives this person for the month.
     *
     * @param  array<string, array<string, mixed>>|null  $scores  the scores of the month when the caller already has them (saves working them out twice)
     * @return list<array{code: string, kind: string, amount: string, score: int}>
     */
    public function lines(User $user, Carbon $month, string|float $baseSalary, ?array $scores = null): array
    {
        $rules = $this->rules->all();
        $base = Money::cents($baseSalary);
        $lines = [];
        $scores ??= $this->scores($user, $month);
        foreach ($scores as $code => $s) {
            $rule = $rules[$code];
            if (! $rule['enabled'] || $s['score'] === null) {
                continue;
            }
            $cents = static fn (string $type, float $value): int => $type === 'percent' ? (int) round($base * $value / 100) : Money::cents($value);
            if ($s['score'] >= $rule['bonus_min'] && $rule['bonus_value'] > 0) {
                $lines[] = ['code' => $code, 'kind' => 'bonus', 'amount' => Money::amount($cents($rule['bonus_type'], (float) $rule['bonus_value'])), 'score' => $s['score']];
            } elseif (($tier = $this->tierFor($rule['deduction_tiers'], $s['score'])) !== null) {
                $lines[] = ['code' => $code, 'kind' => 'deduction', 'amount' => Money::amount($cents($tier['type'], (float) $tier['value'])), 'score' => $s['score']];
            }
        }

        // Each day of absence costs a fixed amount, or a percent of one day of the salary (salary / working days of the month).
        $absence = $rules['absence'];
        $days = $scores['punctuality']['absent'] ?? 0;
        if ($absence['enabled'] && $absence['value'] > 0 && $days > 0) {
            $perDay = $absence['type'] === 'percent' ? (int) round($base / max(1, $this->workdays($month->copy()->startOfMonth(), $month->copy()->endOfMonth())->count()) * (float) $absence['value'] / 100) : Money::cents($absence['value']);
            $lines[] = ['code' => 'absence', 'kind' => 'deduction', 'amount' => Money::amount($perDay * $days), 'score' => $days];
        }

        return array_values(array_filter($lines, fn ($l) => Money::cents($l['amount']) > 0));
    }

    /**
     * The deduction tier a score falls in: of all the tiers whose "below" it is under, the strictest one (lowest "below") applies; they do not add up.
     *
     * @param  list<array{below: int, type: string, value: float}>  $tiers
     * @return array{below: int, type: string, value: float}|null
     */
    private function tierFor(array $tiers, int $score): ?array
    {
        return collect($tiers)->filter(fn ($t) => $score < $t['below'])->sortBy('below')->first();
    }

    /** @return Collection<int, Carbon> */
    private function workdays(Carbon $from, Carbon $to): Collection
    {
        $days = collect();
        $workdays = $this->attendance->workday()['days']; // read once, not for every day of the month
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if (in_array($d->dayOfWeek, $workdays, true)) {
                $days->push($d->copy());
            }
        }

        return $days;
    }

    /** On-time days over working days (days of leave do not count; no record is an absence). */
    private function punctuality(User $user, Collection $days): array
    {
        // Days before the school started recording attendance are not held against anyone.
        $started = DB::table('staff_attendances')->min('date');
        $days = $started === null ? collect() : $days->filter(fn (Carbon $d) => $d->toDateString() >= substr((string) $started, 0, 10))->values();
        if ($days->isEmpty()) {
            return ['score' => null, 'done' => 0, 'expected' => 0, 'absent' => 0, 'absent_dates' => []];
        }
        $rows = DB::table('staff_attendances')->where('user_id', $user->id)->whereBetween('date', [$days->first()->toDateString(), $days->last()->toDateString()])->get()->keyBy(fn ($r) => substr((string) $r->date, 0, 10));
        $expected = 0;
        $done = 0;
        $absent = 0;
        $absentDates = [];
        foreach ($days as $day) {
            $status = $rows[$day->toDateString()]->status ?? 'absent';
            if ($status === 'leave') {
                continue;
            }
            $expected++;
            $done += $status === 'present' ? 1 : 0;
            $absent += $status === 'absent' ? 1 : 0;
            if ($status === 'absent') {
                $absentDates[] = $day->toDateString();
            }
        }

        return $this->result($done, $expected) + ['absent' => $absent, 'absent_dates' => $absentDates];
    }

    /** A published summary for each scheduled lesson (class + subject) on each working day so far. */
    private function dailySummaries(User $user, Collection $days): array
    {
        if ($days->isEmpty()) {
            return $this->result(0, 0);
        }
        $lessons = $this->lessonsByDay($user, $days);
        $written = DB::table('daily_summaries')->where('teacher_id', $user->id)->whereNotNull('published_at')
            ->whereBetween('date', [$days->first()->toDateString(), $days->last()->toDateString()])->get(['date', 'classroom_id', 'subject_id'])
            ->groupBy(fn ($s) => substr((string) $s->date, 0, 10))->map(fn ($rows) => $rows->map(fn ($s) => $s->classroom_id.'-'.$s->subject_id));
        $expected = 0;
        $done = 0;
        foreach ($days as $day) {
            $day = $day->toDateString();
            $expected += $lessons[$day]->count();
            $done += $lessons[$day]->filter(fn ($l) => ($written[$day] ?? collect())->contains($l))->count();
        }

        return $this->result($done, $expected);
    }

    /** A submitted or approved lesson plan for each class and subject taught, for each week of the month. */
    private function weeklyPlans(User $user, Carbon $from, Carbon $to): array
    {
        $allDays = $this->workdays($from, $to);
        if ($allDays->isEmpty()) {
            return $this->result(0, 0);
        }
        $lessons = $this->lessonsByDay($user, $allDays);
        $plans = DB::table('lesson_plans')->where('teacher_id', $user->id)->whereIn('status', ['submitted', 'approved'])
            ->whereBetween('week_starts_on', [$from->copy()->startOfWeek(Carbon::SUNDAY)->toDateString(), $to->toDateString()])->get(['week_starts_on', 'classroom_id', 'subject_id'])
            ->map(fn ($p) => [substr((string) $p->week_starts_on, 0, 10), $p->classroom_id.'-'.$p->subject_id]);
        $expected = 0;
        $done = 0;
        for ($week = $from->copy()->startOfWeek(Carbon::SUNDAY); $week->lte($to); $week->addWeek()) {
            $start = $week->toDateString();
            $end = $week->copy()->addDays(6)->toDateString();
            $pairs = $allDays->filter(fn ($d) => $d->toDateString() >= $start && $d->toDateString() <= $end)->flatMap(fn ($d) => $lessons[$d->toDateString()])->unique()->values();
            if ($pairs->isEmpty()) {
                continue;
            }
            $planned = $plans->filter(fn ($p) => $p[0] >= $start && $p[0] <= $end)->map(fn ($p) => $p[1]);
            $expected += $pairs->count();
            $done += $pairs->filter(fn ($p) => $planned->contains($p))->count();
        }

        return $this->result($done, $expected);
    }

    /**
     * "classroom-subject" of the lessons the teacher has on each of these days, from the timetable of the academic year each day falls in.
     * Two queries for the whole range (the years, the timetable), not two per day.
     *
     * @param  Collection<int, Carbon>  $days
     * @return array<string, Collection<int, string>> keyed by date (Y-m-d)
     */
    private function lessonsByDay(User $user, Collection $days): array
    {
        $years = DB::table('academic_years')->get(['id', 'starts_on', 'ends_on']);
        $entries = DB::table('timetable_entries')->join('periods', 'periods.id', '=', 'timetable_entries.period_id')->where('timetable_entries.teacher_id', $user->id)
            ->get(['timetable_entries.academic_year_id', 'timetable_entries.classroom_id', 'timetable_entries.subject_id', 'periods.day_of_week'])
            ->groupBy(fn ($e) => $e->academic_year_id.'|'.$e->day_of_week);
        $out = [];
        foreach ($days as $day) {
            $date = $day->toDateString();
            $year = $years->first(fn ($y) => substr((string) $y->starts_on, 0, 10) <= $date && substr((string) $y->ends_on, 0, 10) >= $date)?->id;
            $out[$date] = $year === null ? collect() : ($entries[$year.'|'.$day->dayOfWeek] ?? collect())->map(fn ($e) => $e->classroom_id.'-'.$e->subject_id)->unique()->values();
        }

        return $out;
    }

    /** @return array{score: int|null, done: int, expected: int} */
    private function result(int $done, int $expected): array
    {
        return ['score' => $expected === 0 ? null : (int) round($done / $expected * 100), 'done' => $done, 'expected' => $expected];
    }
}
