<?php

declare(strict_types=1);

namespace App\Modules\Academic\Services;

use App\Modules\Academic\Models\Floor;
use App\Modules\Academic\Models\Room;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes a floor have the wanted number of classrooms and labs: adds named rooms, or removes unused ones from the end.
 * A room that is in use is never removed; when the count cannot be reached nothing changes and the request fails.
 */
class SyncFloorRooms
{
    /** request field => room kind */
    private const FIELDS = ['classrooms' => 'classroom', 'science_labs' => 'science_lab', 'computer_labs' => 'computer_lab'];

    /**
     * @param  array<string, int>  $counts  keyed by the request field (classrooms, science_labs, computer_labs)
     */
    public function handle(Floor $floor, array $counts): void
    {
        DB::transaction(function () use ($floor, $counts): void {
            $errors = [];
            foreach (self::FIELDS as $field => $kind) {
                $wanted = (int) ($counts[$field] ?? 0);
                $rooms = $floor->rooms()->where('kind', $kind)->get();

                if ($rooms->count() < $wanted) {
                    for ($i = $rooms->count(); $i < $wanted; $i++) {
                        $this->create($floor, $kind);
                    }
                } elseif ($rooms->count() > $wanted) {
                    $removable = $rooms->reverse()->reject(fn (Room $room) => $room->isInUse())->values();
                    $excess = $rooms->count() - $wanted;
                    if ($removable->count() < $excess) {
                        $errors[$field] = [__('academic.rooms_in_use', ['count' => $rooms->count() - $removable->count()])];

                        continue;
                    }
                    $removable->take($excess)->each->delete();
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors); // rolls everything back
            }
        });
    }

    private function create(Floor $floor, string $kind): Room
    {
        $buildingRooms = Room::whereHas('floor', fn ($q) => $q->where('building_id', $floor->building_id));
        $taken = (clone $buildingRooms)->pluck('name')->all();

        // classrooms: level + sequence (001, 102...); labs: SL-<level>-<n> / CL-<level>-<n>
        $n = $floor->rooms()->where('kind', $kind)->count();
        do {
            $n++;
            $name = match ($kind) {
                'science_lab' => "SL-{$floor->level}-$n",
                'computer_lab' => "CL-{$floor->level}-$n",
                default => $floor->level.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            };
        } while (in_array($name, $taken, true));

        return Room::create([
            'floor_id' => $floor->id, 'name' => $name, 'kind' => $kind, 'type' => $kind === 'classroom' ? 'classroom' : 'lab',
        ]);
    }
}
