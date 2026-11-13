<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use App\Models\User;
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
        // Never store secrets (password hash, remember token): keep the key so the log still shows it changed.
        $mask = fn (?array $values): ?array => $values === null ? null : collect($values)
            ->map(fn ($value, $key) => in_array($key, $model->getHidden(), true) ? '[hidden]' : $value)->all();
        $old = $mask($old);
        $new = $mask($new);

        // Only a person of this school can be the actor: a Super Admin (who creates a school's first account from the central app)
        // has an id in another database, which would break the foreign key to this school's users.
        $actor = auth()->user();

        AuditLog::create([
            'user_id' => $actor instanceof User ? $actor->getKey() : null,
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
