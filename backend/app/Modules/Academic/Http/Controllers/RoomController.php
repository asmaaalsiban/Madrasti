<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** The rooms (admin): list them and rename one. They are created from the counts of a floor. */
class RoomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Room::class);

        $rooms = Room::query()->with('floor.building')->orderBy('floor_id')->orderBy('id')
            ->when($request->filled('floor_id'), fn ($q) => $q->where('floor_id', (int) $request->query('floor_id')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', (string) $request->query('kind')))
            ->get();

        return response()->json(['data' => $rooms->map(fn (Room $room) => $this->present($room))->values()]);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        Gate::authorize('update', $room);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'capacity' => ['nullable', 'integer', 'between:1,1000']]);

        $taken = Room::whereKeyNot($room->id)->where('name', $data['name'])
            ->whereHas('floor', fn ($q) => $q->where('building_id', $room->floor?->building_id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => [__('academic.room_name_taken')]]);
        }
        $room->update($data);

        return response()->json(['data' => $this->present($room->load('floor.building'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Room $room): array
    {
        return [
            'id' => $room->id, 'name' => $room->name, 'kind' => $room->kind, 'capacity' => $room->capacity,
            'label' => collect([$room->floor?->building?->name, $room->floor?->name, $room->name])->filter()->implode(' · '),
            'floor' => $room->floor?->only(['id', 'name', 'level']),
            'building' => $room->floor?->building?->only(['id', 'name']),
        ];
    }
}
