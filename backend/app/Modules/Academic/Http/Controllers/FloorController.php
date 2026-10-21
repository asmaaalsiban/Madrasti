<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Http\Requests\FloorRequest;
use App\Modules\Academic\Http\Resources\FloorResource;
use App\Modules\Academic\Models\Floor;
use App\Modules\Academic\Services\SyncFloorRooms;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** A floor of a building: its grade range, gender and how many classrooms and labs it has (admin). */
class FloorController extends Controller
{
    private const WITH = ['rooms', 'gradeFrom', 'gradeTo', 'building'];

    public function store(FloorRequest $request, SyncFloorRooms $rooms): JsonResponse
    {
        Gate::authorize('create', Floor::class);

        $floor = DB::transaction(function () use ($request, $rooms): Floor {
            $floor = Floor::create($request->safe()->only(['building_id', 'name', 'level', 'gender', 'grade_from_id', 'grade_to_id']));
            $rooms->handle($floor, $request->safe()->only(['classrooms', 'science_labs', 'computer_labs']));

            return $floor;
        });

        return (new FloorResource($floor->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function update(FloorRequest $request, Floor $floor, SyncFloorRooms $rooms): FloorResource
    {
        Gate::authorize('update', $floor);

        DB::transaction(function () use ($request, $floor, $rooms): void {
            $floor->update($request->safe()->only(['building_id', 'name', 'level', 'gender', 'grade_from_id', 'grade_to_id']));
            $rooms->handle($floor, $request->safe()->only(['classrooms', 'science_labs', 'computer_labs']));
        });

        return new FloorResource($floor->load(self::WITH));
    }

    public function destroy(Floor $floor): JsonResponse
    {
        Gate::authorize('delete', $floor);
        DB::transaction(fn () => self::removeWithRooms($floor->load('rooms')));

        return response()->json(null, 204);
    }

    /** Removes a floor and its rooms unless one of the rooms is in use. */
    public static function removeWithRooms(Floor $floor): void
    {
        if ($floor->rooms->contains(fn ($room) => $room->isInUse())) {
            abort(422, __('academic.in_use', ['resource' => __('academic.resources.floor')]));
        }
        $floor->rooms->each->delete();
        $floor->delete();
    }
}
