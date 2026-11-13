<?php

declare(strict_types=1);

namespace App\Central\Http\Controllers;

use App\Central\Actions\CreateSchool;
use App\Central\Http\Requests\StoreTenantRequest;
use App\Central\Http\Requests\UpdateTenantRequest;
use App\Central\Http\Resources\TenantResource;
use App\Central\Models\Tenant;
use App\Central\Services\CentralAudit;
use App\Central\Support\SchoolState;
use App\Central\Support\SchoolStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class TenantController extends Controller
{
    private const PER_PAGE = 20;

    /** Schools, newest first; ?search= matches the name or code, ?state= one of the SchoolState values. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'in:'.implode(',', array_column(SchoolState::cases(), 'value'))],
        ]);

        $query = Tenant::with('domains')->latest();
        if (filled($data['search'] ?? null)) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['search']).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('id', 'like', $like));
        }

        if (blank($data['state'] ?? null)) {
            return TenantResource::collection($query->paginate(self::PER_PAGE));
        }

        // The state is derived from several columns and the clock, so it is filtered here, not in SQL.
        $matching = $query->get()->filter(fn (Tenant $t) => SchoolStatus::of($t)->value === $data['state'])->values();
        $page = LengthAwarePaginator::resolveCurrentPage();

        return TenantResource::collection(new LengthAwarePaginator(
            $matching->forPage($page, self::PER_PAGE)->values(), $matching->count(), self::PER_PAGE, $page, ['path' => $request->url(), 'query' => $request->query()],
        ));
    }

    public function store(StoreTenantRequest $request, CreateSchool $createSchool, CentralAudit $audit): TenantResource
    {
        $tenant = $createSchool->handle($request->validated());
        $audit->record('school.create', $tenant->getTenantKey());

        return new TenantResource($tenant->load('domains'));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant, CentralAudit $audit): TenantResource
    {
        $tenant->update($request->validated());
        $audit->record('school.update', $tenant->getTenantKey(), ['fields' => array_keys($request->validated())]);

        return new TenantResource($tenant->load('domains'));
    }
}
