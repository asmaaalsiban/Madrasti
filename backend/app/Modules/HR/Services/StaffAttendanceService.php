<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\StaffAttendance;
use App\Modules\Scheduling\Services\PeriodSetup;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The staff's working day. Each person checks in when they arrive and out when they leave; a check-in after the start of the
 * day plus the grace minutes is "late". Management can fix any day. A working day with no record counts as an absence.
 */
class StaffAttendanceService
{
    public function __construct(private readonly PeriodSetup $periods, private readonly CommitmentRules $rules) {}

    /** The days of the week the school works (0 = Sunday) and when the day starts. @return array{days: list<int>, starts_at: string, grace: int} */
    public function workday(): array
    {
        $setup = $this->periods->current();

        return ['days' => array_map('intval', $setup['days']), 'starts_at' => (string) $setup['starts_at'], 'grace' => $this->rules->graceMinutes()];
    }

    public function isWorkday(Carbon $date): bool
    {
        return in_array($date->dayOfWeek, $this->workday()['days'], true);
    }

    public function checkIn(User $user): StaffAttendance
    {
        $now = now();
        if (! $this->isWorkday($now)) {
            throw ValidationException::withMessages(['date' => [__('payroll.not_workday')]]);
        }
        $existing = StaffAttendance::where('user_id', $user->id)->whereDate('date', $now->toDateString())->first();
        if ($existing !== null && $existing->check_in !== null) {
            return $existing; // pressed twice: the first time counts
        }
        $work = $this->workday();
        $limit = Carbon::parse($now->toDateString().' '.$work['starts_at'])->addMinutes($work['grace']);
        $status = $now->gt($limit) ? 'late' : 'present';

        return StaffAttendance::updateOrCreate(['user_id' => $user->id, 'date' => $now->toDateString()], ['check_in' => $now->format('H:i:s'), 'status' => $status, 'check_out' => null, 'worked_minutes' => 0]);
    }

    public function checkOut(User $user): StaffAttendance
    {
        $now = now();
        $row = StaffAttendance::where('user_id', $user->id)->whereDate('date', $now->toDateString())->first();
        if ($row === null || $row->check_in === null) {
            throw ValidationException::withMessages(['date' => [__('payroll.no_check_in')]]);
        }
        $row->update(['check_out' => $now->format('H:i:s'), 'worked_minutes' => max(0, (int) Carbon::parse($now->toDateString().' '.$row->check_in)->diffInMinutes($now))]);

        return $row;
    }

    /**
     * Management sets a day by hand (an absence with an excuse, a forgotten check-in...). `late` is worked out from the time when
     * it is given and the status is present/late.
     *
     * @param  array{status: string, check_in?: string|null, check_out?: string|null, note?: string|null}  $data
     */
    public function record(User $user, string $date, array $data): StaffAttendance
    {
        $in = $data['check_in'] ?? null;
        $out = $data['check_out'] ?? null;
        $status = $data['status'];
        if (in_array($status, ['absent', 'leave'], true)) {
            $in = $out = null;
        } elseif ($in !== null) {
            $work = $this->workday();
            $status = Carbon::parse("$date $in")->gt(Carbon::parse("$date ".$work['starts_at'])->addMinutes($work['grace'])) ? 'late' : 'present';
        }
        $worked = $in !== null && $out !== null ? max(0, (int) Carbon::parse("$date $in")->diffInMinutes(Carbon::parse("$date $out"))) : 0;

        return StaffAttendance::updateOrCreate(['user_id' => $user->id, 'date' => $date], ['status' => $status, 'check_in' => $in, 'check_out' => $out, 'worked_minutes' => $worked, 'note' => $data['note'] ?? null]);
    }
}
