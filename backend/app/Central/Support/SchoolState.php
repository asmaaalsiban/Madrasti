<?php

declare(strict_types=1);

namespace App\Central\Support;

/**
 * Lifecycle state of a school, derived from its tenant row (never stored).
 */
enum SchoolState: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case ExitWindow = 'exit_window';
    case Closed = 'closed';
}
