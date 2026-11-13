<?php

declare(strict_types=1);

namespace App\Central\Support;

use App\Central\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Works out a school's lifecycle state from its tenant row.
 */
final class SchoolStatus
{
    public static function of(Tenant $school, ?CarbonInterface $now = null): SchoolState
    {
        $now ??= Carbon::now();
        $exitEnds = $school->exit_window_ends_at;

        if ($exitEnds !== null && $exitEnds->greaterThan($now)) {
            return SchoolState::ExitWindow;
        }

        if ($school->closed_at !== null || $exitEnds !== null) {
            return SchoolState::Closed;
        }

        if (! $school->is_active) {
            return SchoolState::Suspended;
        }

        $ends = $school->subscription_ends_at;
        if ($ends !== null && $ends->copy()->endOfDay()->lessThan($now)) {
            return SchoolState::Expired;
        }

        return SchoolState::Active;
    }

    /** Every state except Active blocks normal use of the school. */
    public static function blocked(Tenant $school, ?CarbonInterface $now = null): bool
    {
        return self::of($school, $now) !== SchoolState::Active;
    }

    /** True when a school is initialised for this request and it is blocked. */
    public static function currentSchoolBlocked(): bool
    {
        $school = tenant();

        return $school instanceof Tenant && self::blocked($school);
    }
}
