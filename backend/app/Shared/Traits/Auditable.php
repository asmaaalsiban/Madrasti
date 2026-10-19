<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit_logs row on every create/update/delete of the model.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $m) => self::audit($m, 'created', null, $m->getAttributes()));

        static::updated(function (Model $m): void {
            $changes = collect($m->getChanges())->except('updated_at');
            if ($changes->isEmpty()) {
                return;
            }
            self::audit($m, 'updated', collect($m->getOriginal())->only($changes->keys())->all(), $changes->all());
        });

        static::deleted(fn (Model $m) => self::audit($m, 'deleted', $m->getAttributes(), null));
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private static function audit(Model $model, string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255) ?: null,
        ]);
    }
}
