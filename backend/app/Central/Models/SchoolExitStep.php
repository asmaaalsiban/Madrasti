<?php

declare(strict_types=1);

namespace App\Central\Models;

use App\Central\Jobs\PrepareExit;
use Illuminate\Database\Eloquent\Model;

/**
 * One preparation step (archive or package) of a school's exit. Lives in the central database.
 *
 * @property string $tenant_id
 * @property string $step
 * @property string $status
 * @property string|null $path
 * @property string|null $error
 */
class SchoolExitStep extends Model
{
    public const ARCHIVE = 'archive';

    public const PACKAGE = 'package';

    public const STEPS = [self::ARCHIVE, self::PACKAGE];

    public const CREATED_AT = null; // only updated_at exists

    protected $guarded = [];

    protected $casts = ['finished_at' => 'datetime', 'updated_at' => 'datetime'];

    /** Always the central database, also when read from inside a school's tenancy. */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    /** Waiting or running, but untouched for longer than a job may run: whatever was working on it is gone. */
    public function isStale(): bool
    {
        return in_array($this->status, ['pending', 'running'], true)
            && ($this->updated_at === null || $this->updated_at->addSeconds(PrepareExit::STALE_AFTER)->isPast());
    }

    /** Waiting or running and recently touched: a job may be working on it. */
    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'running'], true) && ! $this->isStale();
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }
}
