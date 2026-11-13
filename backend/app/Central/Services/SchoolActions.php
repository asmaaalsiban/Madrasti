<?php

declare(strict_types=1);

namespace App\Central\Services;

use App\Central\Models\Tenant;
use Carbon\CarbonInterface;

/**
 * The owner's lifecycle actions on a school. Each one changes only the school's row in the central database and writes the audit.
 */
class SchoolActions
{
    public function __construct(private readonly CentralAudit $audit) {}

    public function suspend(Tenant $school): void
    {
        $school->update(['is_active' => false]);
        $this->audit->record('school.suspend', $school->getTenantKey());
    }

    /** Also brings a closed school back: the window and the closing date are cleared. */
    public function reactivate(Tenant $school): void
    {
        $school->update(['is_active' => true, 'closed_at' => null, 'exit_window_ends_at' => null]);
        $this->audit->record('school.reactivate', $school->getTenantKey());
    }

    public function extend(Tenant $school, CarbonInterface $endsAt): void
    {
        $previous = $school->subscription_ends_at;
        $school->update(['subscription_ends_at' => $endsAt]);
        $this->audit->record('school.extend', $school->getTenantKey(), [
            'from' => $previous?->toDateString(),
            'to' => $endsAt->toDateString(),
        ]);
    }
}
