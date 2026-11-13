<?php

declare(strict_types=1);

namespace App\Central\Http\Controllers;

use App\Central\Http\Resources\TenantResource;
use App\Central\Models\Tenant;
use App\Central\Services\SchoolActions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SchoolActionController extends Controller
{
    public function suspend(Tenant $tenant, SchoolActions $actions): TenantResource
    {
        $actions->suspend($tenant);

        return new TenantResource($tenant->load('domains'));
    }

    public function reactivate(Tenant $tenant, SchoolActions $actions): TenantResource
    {
        $actions->reactivate($tenant);

        return new TenantResource($tenant->load('domains'));
    }

    public function extend(Request $request, Tenant $tenant, SchoolActions $actions): TenantResource
    {
        $data = $request->validate(['subscription_ends_at' => ['required', 'date']]);
        $actions->extend($tenant, Carbon::parse($data['subscription_ends_at'], config('app.timezone')));

        return new TenantResource($tenant->load('domains'));
    }

    /** The newest 100 owner actions on this school. */
    public function audit(Tenant $tenant): JsonResponse
    {
        $rows = DB::connection(config('tenancy.database.central_connection'))->table('central_audit_logs')
            ->leftJoin('super_admins', 'super_admins.id', '=', 'central_audit_logs.super_admin_id')
            ->where('central_audit_logs.tenant_id', $tenant->getTenantKey())
            ->orderByDesc('central_audit_logs.id')->limit(100)
            ->get(['central_audit_logs.id', 'central_audit_logs.action', 'central_audit_logs.meta', 'central_audit_logs.created_at', 'super_admins.name as actor']);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => $r->id,
            'action' => $r->action,
            'actor' => $r->actor,
            'meta' => $r->meta ? json_decode($r->meta, true) : null,
            'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
        ])->all()]);
    }
}
