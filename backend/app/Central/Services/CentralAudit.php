<?php

declare(strict_types=1);

namespace App\Central\Services;

use App\Central\Models\SuperAdmin;
use Illuminate\Support\Facades\DB;

/**
 * Writes the owner's actions to the central audit log, with the signed-in Super Admin as the actor.
 */
class CentralAudit
{
    /** @param array<string, mixed> $meta */
    public function record(string $action, ?string $tenantId, array $meta = []): void
    {
        $user = auth()->user();

        DB::connection(config('tenancy.database.central_connection'))->table('central_audit_logs')->insert([
            'super_admin_id' => $user instanceof SuperAdmin ? $user->getKey() : null,
            'action' => $action,
            'tenant_id' => $tenantId,
            'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
