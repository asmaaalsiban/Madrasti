<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Models\User;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Scheduling\Models\DutyAssignment;
use App\Modules\Scheduling\Models\DutyPost;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff supervision duties. For every working day in the range and every post open that day, picks the people with the fewest
 * duties so far (the one who waited longest first) among the teachers who have no lesson during the post's time and no other duty
 * that day. Duties set by hand (locked) are kept and count. Nothing is saved until `apply`.
 */
class DutyScheduler
{
    public function __construct(private readonly PeriodSetup $periods, private readonly Notifier $notifier) {}

    /**
     * @param  array{from: string, to: string, exclude_dates?: list<string>, post_ids?: list<int>|null, max_per_day?: int}  $o
     * @return array{rows: list<array<string, mixed>>, warnings: list<string>, days: int, posts: int, post_ids: list<int>}
     */
    public function plan(array $o): array
    {
        $posts = DutyPost::where('is_active', true)->when($o['post_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))->orderBy('starts_at')->get();
        if ($posts->isEmpty()) {
            throw ValidationException::withMessages(['post_ids' => [__('duties.no_posts')]]);
        }
        $workdays = array_map('intval', $this->periods->current()['days']);
        $exclude = collect($o['exclude_dates'] ?? [])->flip();
        $max = (int) ($o['max_per_day'] ?? 1);
        $teachers = User::where('role', Role::Teacher)->where('is_active', true)->whereHas('staffProfile', fn ($q) => $q->where('does_duty', true)->whereIn('staff_type', collect(StaffType::cases())->filter(fn ($t) => $t->category() === 'teaching')->map->value->all()))->orderBy('name')->get(['id', 'name']);
        if ($teachers->isEmpty()) {
            throw ValidationException::withMessages(['from' => [__('duties.no_staff')]]);
        }
        $lessons = DB::table('timetable_entries')->join('periods', 'periods.id', '=', 'timetable_entries.period_id')->get(['timetable_entries.teacher_id', 'periods.day_of_week', 'periods.starts_at', 'periods.ends_at'])->groupBy('teacher_id');

        // What is already there: locked duties in the range (kept) and the history that decides who is next.
        $locked = DutyAssignment::whereBetween('date', [$o['from'], $o['to']])->where('is_locked', true)->get();
        // History decides who is next, without the unlocked duties this run is about to replace.
        $history = fn () => DutyAssignment::where(fn ($q) => $q->whereNotBetween('date', [$o['from'], $o['to']])->orWhere('is_locked', true)->orWhereNotIn('duty_post_id', $posts->pluck('id')));
        $count = $history()->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id')->map(fn ($n) => (int) $n)->all();
        $last = $history()->selectRaw('user_id, max(date) as d')->groupBy('user_id')->pluck('d', 'user_id')->all();
        $today = [];
        foreach ($locked as $l) {
            $today[$l->date->toDateString()][$l->user_id] = ($today[$l->date->toDateString()][$l->user_id] ?? 0) + 1;
        }

        $rows = [];
        $warnings = [];
        $days = 0;
        for ($d = Carbon::parse($o['from']); $d->lte(Carbon::parse($o['to'])); $d->addDay()) {
            if (! in_array($d->dayOfWeek, $workdays, true) || $exclude->has($d->toDateString())) {
                continue;
            }
            $date = $d->toDateString();
            $used = false;
            foreach ($posts as $post) {
                if ($post->days !== null && ! in_array($d->dayOfWeek, array_map('intval', $post->days), true)) {
                    continue;
                }
                $used = true;
                $start = substr((string) $post->starts_at, 0, 8);
                $end = substr((string) $post->ends_at, 0, 8);
                $has = collect($locked)->where('duty_post_id', $post->id)->filter(fn ($l) => $l->date->toDateString() === $date)->count();
                $free = $teachers->filter(fn ($t) => ($today[$date][$t->id] ?? 0) < $max
                    && ! ($lessons[$t->id] ?? collect())->contains(fn ($l) => (int) $l->day_of_week === $d->dayOfWeek && $l->starts_at < $end && $l->ends_at > $start))
                    ->sortBy(fn ($t) => [$count[$t->id] ?? 0, $last[$t->id] ?? '0000-00-00', $t->id])->values();
                $need = max(0, $post->staff_count - $has);
                foreach ($free->take($need) as $t) {
                    $rows[] = ['user_id' => $t->id, 'duty_post_id' => $post->id, 'date' => $date, 'starts_at' => $start, 'ends_at' => $end, 'location' => $post->name];
                    $today[$date][$t->id] = ($today[$date][$t->id] ?? 0) + 1;
                    $count[$t->id] = ($count[$t->id] ?? 0) + 1;
                    $last[$t->id] = $date;
                }
                if ($free->count() < $need) {
                    $warnings[] = __('duties.short_of_staff', ['post' => $post->name, 'date' => $date, 'got' => $free->count() + $has, 'needed' => $post->staff_count]);
                }
            }
            $days += $used ? 1 : 0;
        }
        if ($rows === [] && $days === 0) {
            throw ValidationException::withMessages(['from' => [__('duties.no_days')]]);
        }

        return ['rows' => $rows, 'warnings' => array_values(array_unique($warnings)), 'days' => $days, 'posts' => $posts->count(), 'post_ids' => $posts->pluck('id')->all()];
    }

    /**
     * Replaces the unlocked duties of these posts in the range with the plan and tells the people who got one.
     *
     * @param  array{rows: list<array<string, mixed>>, post_ids: list<int>}  $plan
     */
    public function apply(array $plan, string $from, string $to): void
    {
        DB::transaction(function () use ($plan, $from, $to): void {
            DutyAssignment::whereIn('duty_post_id', $plan['post_ids'])->whereBetween('date', [$from, $to])->where('is_locked', false)->delete();
            foreach ($plan['rows'] as $row) {
                DutyAssignment::create($row);
            }
        });
        $this->notifier->toUsers(collect($plan['rows'])->pluck('user_id')->unique(), 'duty_assigned', ['title' => $from.' → '.$to], '/teacher/duties');
    }

    /** Tells everyone who is on duty on `$date` (default tomorrow). Returns how many people were told. */
    public function remind(?string $date = null): int
    {
        $date ??= now()->addDay()->toDateString();
        $byUser = DutyAssignment::whereDate('date', $date)->orderBy('starts_at')->get()->groupBy('user_id');
        foreach ($byUser as $userId => $duties) {
            $text = $duties->map(fn ($d) => $d->location.' '.substr((string) $d->starts_at, 0, 5))->implode('، ');
            $this->notifier->toUsers([$userId], 'duty_reminder', ['title' => $text, 'date' => $date], '/teacher/duties');
        }

        return $byUser->count();
    }
}
