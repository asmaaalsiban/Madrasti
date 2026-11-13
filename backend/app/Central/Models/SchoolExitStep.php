<?php

declare(strict_types=1);

namespace App\Central\Models;

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

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['finished_at' => 'datetime'];

    /** Always the central database, also when read from inside a school's tenancy. */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }
}
