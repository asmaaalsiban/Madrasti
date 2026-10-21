<?php

declare(strict_types=1);

namespace App\Modules\Academic\Services;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Room;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates the sections (classrooms) of every grade for an academic year and places each in a free classroom of a
 * floor that serves the grade and fits the gender. Only what is missing is created, so it is safe to run again.
 *
 * A section without a gender (created before genders existed) counts as mixed. Sections are named <code>-<letter>
 * (KG1-A, 4-B); the code is the grade's code or, without one, its sort order.
 */
class ClassroomGenerator
{
    private const GENDERS = ['male', 'female', 'mixed'];

    /**
     * @param  list<array{grade_level_id: int, male: int, female: int, mixed: int}>  $plan
     * @return array{dry_run: bool, created: int, placed: int, unplaced: list<string>, classrooms: list<array<string, mixed>>}
     */
    public function handle(AcademicYear $year, array $plan, bool $dryRun): array
    {
        $grades = GradeLevel::whereIn('id', array_column($plan, 'grade_level_id'))->get()->keyBy('id');
        $existing = Classroom::where('academic_year_id', $year->id)->get();
        $usedNames = $existing->pluck('name')->all();
        $takenRooms = $existing->whereNotNull('room_id')->pluck('room_id')->flip()->all();
        $rooms = $this->rooms();

        $result = [];
        $run = function () use ($plan, $grades, $existing, $year, $rooms, &$usedNames, &$takenRooms, &$result, $dryRun): void {
            foreach ($plan as $row) {
                $grade = $grades[$row['grade_level_id']];
                foreach (self::GENDERS as $gender) {
                    $have = $existing->where('grade_level_id', $grade->id)->filter(fn (Classroom $c) => ($c->gender ?? 'mixed') === $gender)->count();
                    for ($i = $have; $i < (int) $row[$gender]; $i++) {
                        $name = $this->nextName($grade, $usedNames);
                        $usedNames[] = $name;
                        $room = $this->pickRoom($rooms, $takenRooms, $grade, $gender);
                        if ($room !== null) {
                            $takenRooms[$room->id] = true;
                        }
                        if (! $dryRun) {
                            Classroom::create([
                                'name' => $name, 'grade_level_id' => $grade->id, 'academic_year_id' => $year->id,
                                'gender' => $gender, 'room_id' => $room?->id,
                            ]);
                        }
                        $result[] = [
                            'name' => $name, 'grade_level_id' => $grade->id, 'gender' => $gender,
                            'room' => $room ? ['id' => $room->id, 'name' => $room->name, 'building' => $room->floor->building->name, 'floor' => $room->floor->name] : null,
                        ];
                    }
                }
            }
        };
        $dryRun ? $run() : DB::transaction($run);

        return [
            'dry_run' => $dryRun,
            'created' => count($result),
            'placed' => count(array_filter($result, fn (array $c) => $c['room'] !== null)),
            'unplaced' => array_values(array_map(fn (array $c) => $c['name'], array_filter($result, fn (array $c) => $c['room'] === null))),
            'classrooms' => $result,
        ];
    }

    /** @return Collection<int, Room> classrooms of floors, in the order they are filled: building, floor level, room */
    private function rooms(): Collection
    {
        return Room::where('kind', 'classroom')->whereNotNull('floor_id')->with(['floor.building', 'floor.gradeFrom', 'floor.gradeTo'])->get()
            ->sortBy(fn (Room $r) => sprintf('%s|%05d|%08d', $r->floor->building->name, $r->floor->level + 100, $r->id))->values();
    }

    /**
     * @param  Collection<int, Room>  $rooms
     * @param  array<int, bool>  $taken
     */
    private function pickRoom(Collection $rooms, array $taken, GradeLevel $grade, string $gender): ?Room
    {
        $fits = $rooms->filter(function (Room $room) use ($taken, $grade, $gender): bool {
            if (isset($taken[$room->id])) {
                return false;
            }
            $floor = $room->floor;
            $serves = $floor->gradeFrom === null || $floor->gradeTo === null
                || ($floor->gradeFrom->sort_order <= $grade->sort_order && $grade->sort_order <= $floor->gradeTo->sort_order);
            $floorGender = $floor->effectiveGender();

            return $serves && ($floorGender === $gender || ($floorGender === 'mixed' && $gender !== 'mixed') || ($gender === 'mixed' && $floorGender === 'mixed'));
        });

        // A floor made for exactly this gender before a mixed one.
        return $fits->first(fn (Room $r) => $r->floor->effectiveGender() === $gender) ?? $fits->first();
    }

    /** @param  list<string>  $usedNames */
    private function nextName(GradeLevel $grade, array $usedNames): string
    {
        $prefix = ($grade->code ?? '') !== '' ? $grade->code : (string) $grade->sort_order;
        for ($n = 0; ; $n++) {
            $letters = '';
            for ($k = $n; $k >= 0; $k = intdiv($k, 26) - 1) {
                $letters = chr(65 + $k % 26).$letters;
            }
            if (! in_array("$prefix-$letters", $usedNames, true)) {
                return "$prefix-$letters";
            }
        }
    }
}
