<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Http\Requests\BuildingRequest;
use App\Modules\Academic\Http\Resources\BuildingResource;
use App\Modules\Academic\Models\Building;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** The buildings of the school with their floors (admin). A school has a handful of them, so the list is not paged. */
class BuildingController extends Controller
{
    private const WITH = ['floors.rooms', 'floors.gradeFrom', 'floors.gradeTo', 'floors.building'];

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Building::class);

        return response()->json(['data' => BuildingResource::collection(Building::with(self::WITH)->orderBy('name')->get())->resolve()]);
    }

    public function store(BuildingRequest $request): JsonResponse
    {
        Gate::authorize('create', Building::class);

        return (new BuildingResource(Building::create($request->validated())->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function update(BuildingRequest $request, Building $building): BuildingResource
    {
        Gate::authorize('update', $building);
        $building->update($request->validated());

        return new BuildingResource($building->load(self::WITH));
    }

    public function destroy(Building $building): JsonResponse
    {
        Gate::authorize('delete', $building);

        DB::transaction(function () use ($building): void {
            foreach ($building->floors()->with('rooms')->get() as $floor) {
                FloorController::removeWithRooms($floor);
            }
            $building->delete();
        });

        return response()->json(null, 204);
    }
}
