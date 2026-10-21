<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The bells of the school week: which days are school days, how many periods a day, when the day starts, how long a period
 * and a break last, and an optional long break after one period. Creates / updates the periods; a period that a timetable
 * already uses is never removed (the change is refused).
 */
class PeriodSetup
{
    private const DEFAULT = [
        'days' => [0, 1, 2, 3, 4], 'periods_per_day' => 7, 'starts_at' => '07:45', 'period_minutes' => 45, 'gap_minutes' => 5,
        'long_break_after' => 3, 'long_break_minutes' => 20,
    ];

    /** @return array<string, mixed> */
    public function current(): array
    {
        $stored = DB::table('school_settings')->where('key', 'period_setup')->value('value');

        return $stored !== null ? json_decode($stored, true) + self::DEFAULT : self::DEFAULT;
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    public function apply(array $setup): void
    {
        $days = collect($setup['days'])->map(fn ($d) => (int) $d)->sort()->values();
        $wanted = [];
        foreach ($days as $day) {
            $minute = $this->minutes((string) $setup['starts_at']);
            for ($n = 1; $n <= (int) $setup['periods_per_day']; $n++) {
                $end = $minute + (int) $setup['period_minutes'];
                $wanted["$day-$n"] = ['day_of_week' => $day, 'number' => $n, 'starts_at' => $this->time($minute), 'ends_at' => $this->time($end)];
                $isLong = ($setup['long_break_after'] ?? null) !== null && (int) $setup['long_break_after'] === $n;
                $minute = $end + ($isLong ? (int) $setup['long_break_minutes'] : (int) $setup['gap_minutes']);
            }
        }

        DB::transaction(function () use ($setup, $wanted, $days): void {
            $existing = Period::all()->keyBy(fn (Period $p) => $p->day_of_week.'-'.$p->number);

            $removed = $existing->diffKeys($wanted);
            $used = $removed->filter(fn (Period $p) => DB::table('timetable_entries')->where('period_id', $p->id)->exists());
            if ($used->isNotEmpty()) {
                $dayRemoved = $used->contains(fn (Period $p) => ! $days->contains($p->day_of_week));
                throw ValidationException::withMessages([$dayRemoved ? 'days' : 'periods_per_day' => [__('scheduling.periods_in_use')]]);
            }
            $removed->each->delete();

            foreach ($wanted as $key => $row) {
                $existing->has($key) ? $existing[$key]->update($row) : Period::create($row);
            }

            DB::table('school_settings')->updateOrInsert(
                ['key' => 'period_setup'],
                ['value' => json_encode(collect($setup)->only(array_keys(self::DEFAULT))->all()), 'created_at' => now(), 'updated_at' => now()],
            );
        });
    }

    /** Minutes after midnight of the end of the last period. */
    public function endOfDay(array $setup): int
    {
        $minute = $this->minutes((string) $setup['starts_at']);
        for ($n = 1; $n <= (int) $setup['periods_per_day']; $n++) {
            $minute += (int) $setup['period_minutes'];
            if ($n < (int) $setup['periods_per_day']) {
                $minute += ($setup['long_break_after'] ?? null) !== null && (int) $setup['long_break_after'] === $n ? (int) $setup['long_break_minutes'] : (int) $setup['gap_minutes'];
            }
        }

        return $minute;
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private function time(int $minutes): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }
}
