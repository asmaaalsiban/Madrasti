<?php

declare(strict_types=1);

namespace App\Central\Http\Controllers;

use App\Central\Actions\CreateSchool;
use App\Central\Http\Requests\StoreTenantRequest;
use App\Central\Http\Requests\UpdateTenantRequest;
use App\Central\Http\Resources\TenantResource;
use App\Central\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TenantController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TenantResource::collection(Tenant::with('domains')->latest()->paginate(20));
    }

    public function store(StoreTenantRequest $request, CreateSchool $createSchool): TenantResource
    {
        $tenant = $createSchool->handle($request->validated());

        return new TenantResource($tenant->load('domains'));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        $tenant->update($request->validated());

        return new TenantResource($tenant->load('domains'));
    }
}
