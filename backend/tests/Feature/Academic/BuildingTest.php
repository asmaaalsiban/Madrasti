<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\Building;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Floor;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Room;
use App\Shared\Enums\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class BuildingTest extends AcademicTestCase
{
    private function building(array $override = []): Building
    {
        return Building::create([...['name' => 'Boys building', 'gender' => 'male'], ...$override]);
    }

    private function floorBody(Building $building, array $override = []): array
    {
        return [...[
            'building_id' => $building->id, 'name' => 'Ground floor', 'level' => 0, 'gender' => null,
            'grade_from_id' => null, 'grade_to_id' => null, 'classrooms' => 3, 'science_labs' => 1, 'computer_labs' => 1,
        ], ...$override];
    }

    public function test_admin_can_crud_buildings(): void
    {
        $this->actAsAdmin();

        $id = $this->postJson('/api/buildings', ['name' => 'Girls building', 'gender' => 'female'])->assertCreated()
            ->assertJsonPath('data.name', 'Girls building')->assertJsonPath('data.gender', 'female')->assertJsonPath('data.floors', [])
            ->json('data.id');
        $this->getJson('/api/buildings')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson("/api/buildings/$id", ['name' => 'Girls school', 'gender' => 'female'])->assertOk()->assertJsonPath('data.name', 'Girls school');
        $this->deleteJson("/api/buildings/$id")->assertNoContent();
    }

    public function test_a_building_needs_a_name_and_a_valid_gender(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/buildings', ['name' => '', 'gender' => 'male'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/buildings', ['name' => 'X', 'gender' => 'robots'])->assertUnprocessable()->assertJsonValidationErrors('gender');
    }

    public function test_a_floor_creates_its_classrooms_and_labs(): void
    {
        $this->actAsAdmin();
        $building = $this->building();
        $from = GradeLevel::factory()->create(['sort_order' => 6]);
        $to = GradeLevel::factory()->create(['sort_order' => 10]);

        $response = $this->postJson('/api/floors', $this->floorBody($building, ['grade_from_id' => $from->id, 'grade_to_id' => $to->id]))->assertCreated()
            ->assertJsonPath('data.name', 'Ground floor')
            ->assertJsonPath('data.effective_gender', 'male')
            ->assertJsonPath('data.grade_from.id', $from->id)
            ->assertJsonPath('data.grade_to.id', $to->id)
            ->assertJsonPath('data.counts.classroom', 3)
            ->assertJsonPath('data.counts.science_lab', 1)
            ->assertJsonPath('data.counts.computer_lab', 1);

        $floor = Floor::findOrFail($response->json('data.id'));
        $this->assertSame(3, $floor->rooms()->where('kind', 'classroom')->count());
        $this->assertSame(['001', '002', '003'], $floor->rooms()->where('kind', 'classroom')->orderBy('id')->pluck('name')->all());
        $this->assertSame(1, $floor->rooms()->where('kind', 'science_lab')->count());
        $this->assertSame(1, $floor->rooms()->where('kind', 'computer_lab')->count());
        $this->getJson('/api/buildings')->assertJsonCount(1, 'data.0.floors');
    }

    public function test_a_floor_can_have_its_own_gender_inside_a_mixed_building(): void
    {
        $this->actAsAdmin();
        $building = $this->building(['gender' => 'mixed']);

        $this->postJson('/api/floors', $this->floorBody($building, ['gender' => 'female']))->assertCreated()->assertJsonPath('data.effective_gender', 'female');
        $this->postJson('/api/floors', $this->floorBody($building, ['level' => 1, 'name' => 'First floor']))->assertCreated()->assertJsonPath('data.effective_gender', 'mixed');
    }

    public function test_changing_the_counts_adds_rooms_and_removes_only_unused_ones(): void
    {
        $this->actAsAdmin();
        $building = $this->building();
        $id = $this->postJson('/api/floors', $this->floorBody($building, ['classrooms' => 4, 'science_labs' => 0, 'computer_labs' => 0]))->json('data.id');
        $floor = Floor::findOrFail($id);

        $this->putJson("/api/floors/$id", $this->floorBody($building, ['classrooms' => 6, 'science_labs' => 2, 'computer_labs' => 0]))->assertOk()->assertJsonPath('data.counts.classroom', 6);
        $this->assertSame(8, $floor->rooms()->count());

        // A room that hosts a classroom stays; the unused ones are removed from the end.
        $used = $floor->rooms()->where('kind', 'classroom')->orderByDesc('id')->first();
        Classroom::factory()->create(['room_id' => $used->id]);
        $this->putJson("/api/floors/$id", $this->floorBody($building, ['classrooms' => 5, 'science_labs' => 0]))->assertOk();
        $this->assertSame(5, $floor->rooms()->where('kind', 'classroom')->count());

        $this->putJson("/api/floors/$id", $this->floorBody($building, ['classrooms' => 0, 'science_labs' => 0]))->assertUnprocessable()->assertJsonValidationErrors('classrooms');
        $this->assertSame(5, $floor->rooms()->where('kind', 'classroom')->count(), 'nothing is removed when the change cannot be completed');
    }

    public function test_floor_validation(): void
    {
        $this->actAsAdmin();
        $building = $this->building();
        $low = GradeLevel::factory()->create(['sort_order' => 2]);
        $high = GradeLevel::factory()->create(['sort_order' => 8]);

        $this->postJson('/api/floors', $this->floorBody($building, ['name' => '']))->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/floors', $this->floorBody($building, ['classrooms' => -1]))->assertUnprocessable()->assertJsonValidationErrors('classrooms');
        $this->postJson('/api/floors', $this->floorBody($building, ['classrooms' => 500]))->assertUnprocessable()->assertJsonValidationErrors('classrooms');
        $this->postJson('/api/floors', $this->floorBody($building, ['gender' => 'robots']))->assertUnprocessable()->assertJsonValidationErrors('gender');
        $this->postJson('/api/floors', $this->floorBody($building, ['building_id' => 999]))->assertUnprocessable()->assertJsonValidationErrors('building_id');
        $this->postJson('/api/floors', $this->floorBody($building, ['grade_from_id' => $high->id, 'grade_to_id' => $low->id]))->assertUnprocessable()->assertJsonValidationErrors('grade_to_id');
        $this->postJson('/api/floors', $this->floorBody($building, ['grade_from_id' => $low->id, 'grade_to_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('grade_to_id');
        $this->assertSame(0, Floor::count());
    }

    public function test_a_level_is_used_once_per_building(): void
    {
        $this->actAsAdmin();
        $building = $this->building();
        $other = $this->building(['name' => 'Girls']);
        $this->postJson('/api/floors', $this->floorBody($building))->assertCreated();

        $this->postJson('/api/floors', $this->floorBody($building, ['name' => 'Another']))->assertUnprocessable()->assertJsonValidationErrors('level');
        $this->postJson('/api/floors', $this->floorBody($other))->assertCreated();
    }

    public function test_room_names_stay_unique_inside_a_building(): void
    {
        $this->actAsAdmin();
        $building = $this->building();
        $this->postJson('/api/floors', $this->floorBody($building, ['classrooms' => 2, 'science_labs' => 0, 'computer_labs' => 0]))->assertCreated();
        $this->postJson('/api/floors', $this->floorBody($building, ['level' => 1, 'name' => 'First', 'classrooms' => 2, 'science_labs' => 0, 'computer_labs' => 0]))->assertCreated();

        $names = Room::query()->whereHas('floor', fn ($q) => $q->where('building_id', $building->id))->pluck('name');
        $this->assertSame($names->count(), $names->unique()->count());
        $this->assertSame(['001', '002', '101', '102'], $names->sort()->values()->all());
    }

    public function test_rooms_can_be_renamed_and_listed(): void
    {
        $this->actAsAdmin();
        $building = $this->building();
        $floorId = $this->postJson('/api/floors', $this->floorBody($building, ['classrooms' => 1, 'science_labs' => 1, 'computer_labs' => 0]))->json('data.id');
        $room = Room::where('floor_id', $floorId)->where('kind', 'classroom')->first();

        $this->putJson("/api/rooms/{$room->id}", ['name' => 'Room A', 'capacity' => 30])->assertOk()->assertJsonPath('data.name', 'Room A')->assertJsonPath('data.capacity', 30);
        $this->getJson("/api/rooms?floor_id=$floorId")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/rooms?floor_id=$floorId&kind=science_lab")->assertJsonCount(1, 'data');
    }

    public function test_deleting_a_floor_or_building_is_refused_while_a_room_is_used(): void
    {
        $this->actAsAdmin();
        $building = $this->building();
        $floorId = $this->postJson('/api/floors', $this->floorBody($building, ['classrooms' => 2, 'science_labs' => 0, 'computer_labs' => 0]))->json('data.id');
        Classroom::factory()->create(['room_id' => Room::where('floor_id', $floorId)->first()->id]);

        $this->deleteJson("/api/floors/$floorId")->assertUnprocessable();
        $this->deleteJson("/api/buildings/{$building->id}")->assertUnprocessable();

        Classroom::query()->update(['room_id' => null]);
        $this->deleteJson("/api/floors/$floorId")->assertNoContent();
        $this->assertSame(0, Room::count(), 'its rooms go with it');
        $this->deleteJson("/api/buildings/{$building->id}")->assertNoContent();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_are_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/buildings')->assertForbidden();
        $this->postJson('/api/floors', [])->assertForbidden();
        $this->getJson('/api/rooms')->assertForbidden();
    }
}
