<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Building;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Floor;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Room;
use App\Modules\Academic\Services\SyncFloorRooms;
use App\Shared\Enums\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class GenerateClassroomsTest extends AcademicTestCase
{
    private AcademicYear $year;

    private GradeLevel $kg1;

    private GradeLevel $grade4;

    private GradeLevel $grade5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $this->kg1 = GradeLevel::factory()->create(['name_en' => 'KG1', 'code' => 'KG1', 'sort_order' => 2]);
        $this->grade4 = GradeLevel::factory()->create(['name_en' => 'Grade 4', 'code' => '4', 'sort_order' => 7]);
        $this->grade5 = GradeLevel::factory()->create(['name_en' => 'Grade 5', 'code' => null, 'sort_order' => 8]);
    }

    private function floor(string $buildingGender, ?GradeLevel $from, ?GradeLevel $to, int $classrooms, int $level = 0, ?string $name = null): Floor
    {
        $building = Building::create(['name' => $name ?? ucfirst($buildingGender).' building', 'gender' => $buildingGender]);
        $floor = Floor::create(['building_id' => $building->id, 'name' => 'Ground', 'level' => $level, 'grade_from_id' => $from?->id, 'grade_to_id' => $to?->id]);
        app(SyncFloorRooms::class)->handle($floor, ['classrooms' => $classrooms, 'science_labs' => 0, 'computer_labs' => 0]);

        return $floor;
    }

    private function plan(array $rows, array $extra = []): array
    {
        return [...['academic_year_id' => $this->year->id, 'plan' => $rows], ...$extra];
    }

    public function test_it_creates_the_sections_of_every_grade_by_gender_and_places_them_in_matching_rooms(): void
    {
        $boys = $this->floor('male', $this->grade4, $this->grade5, 4);
        $girls = $this->floor('female', $this->grade4, $this->grade5, 2);
        $kg = $this->floor('mixed', $this->kg1, $this->kg1, 3);
        $this->actAsAdmin();

        $response = $this->postJson('/api/classrooms/generate', $this->plan([
            ['grade_level_id' => $this->grade4->id, 'male' => 2, 'female' => 1, 'mixed' => 0],
            ['grade_level_id' => $this->kg1->id, 'male' => 0, 'female' => 0, 'mixed' => 3],
        ]))->assertOk()->assertJsonPath('data.created', 6)->assertJsonPath('data.placed', 6)->assertJsonPath('data.unplaced', []);

        $rooms = fn (Floor $f) => Room::where('floor_id', $f->id)->pluck('id')->all();
        $grade4 = Classroom::where('grade_level_id', $this->grade4->id)->orderBy('id')->get();
        $this->assertSame(['4-A', '4-B', '4-C'], $grade4->pluck('name')->all());
        $this->assertSame(['male', 'male', 'female'], $grade4->pluck('gender')->all());
        $this->assertContains($grade4[0]->room_id, $rooms($boys));
        $this->assertContains($grade4[1]->room_id, $rooms($boys));
        $this->assertContains($grade4[2]->room_id, $rooms($girls));
        $kgRooms = Classroom::where('grade_level_id', $this->kg1->id)->get();
        $this->assertSame(['KG1-A', 'KG1-B', 'KG1-C'], $kgRooms->pluck('name')->sort()->values()->all());
        $this->assertEqualsCanonicalizing($rooms($kg), $kgRooms->pluck('room_id')->all());
        $this->assertSame($this->year->id, $grade4[0]->academic_year_id);
        $this->assertSame(6, count($response->json('data.classrooms')));
    }

    public function test_a_preview_changes_nothing(): void
    {
        $this->floor('male', null, null, 2);
        $this->actAsAdmin();

        $this->postJson('/api/classrooms/generate', $this->plan([['grade_level_id' => $this->grade4->id, 'male' => 2, 'female' => 0, 'mixed' => 0]], ['dry_run' => true]))
            ->assertOk()->assertJsonPath('data.created', 2)->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.classrooms.0.name', '4-A')->assertJsonPath('data.classrooms.0.gender', 'male');

        $this->assertSame(0, Classroom::count());
    }

    public function test_running_it_again_only_adds_what_is_missing_and_continues_the_letters(): void
    {
        $this->floor('male', null, null, 6);
        $this->actAsAdmin();
        $row = fn (int $male) => [['grade_level_id' => $this->grade4->id, 'male' => $male, 'female' => 0, 'mixed' => 0]];

        $this->postJson('/api/classrooms/generate', $this->plan($row(2)))->assertOk()->assertJsonPath('data.created', 2);
        $this->postJson('/api/classrooms/generate', $this->plan($row(2)))->assertOk()->assertJsonPath('data.created', 0);
        $this->postJson('/api/classrooms/generate', $this->plan($row(3)))->assertOk()->assertJsonPath('data.created', 1);

        $this->assertSame(['4-A', '4-B', '4-C'], Classroom::orderBy('id')->pluck('name')->all());
    }

    public function test_existing_classrooms_without_a_gender_count_as_mixed(): void
    {
        $this->floor('mixed', null, null, 4);
        Classroom::factory()->create(['name' => '4-A', 'grade_level_id' => $this->grade4->id, 'academic_year_id' => $this->year->id, 'gender' => null]);
        $this->actAsAdmin();

        $this->postJson('/api/classrooms/generate', $this->plan([['grade_level_id' => $this->grade4->id, 'male' => 0, 'female' => 0, 'mixed' => 3]]))
            ->assertOk()->assertJsonPath('data.created', 2)->assertJsonPath('data.classrooms.0.name', '4-B');
    }

    public function test_sections_that_find_no_room_are_still_created_and_reported(): void
    {
        $this->floor('male', $this->grade4, $this->grade4, 1);
        $this->floor('male', $this->kg1, $this->kg1, 5, 1, 'Other boys');
        $this->actAsAdmin();

        $response = $this->postJson('/api/classrooms/generate', $this->plan([
            ['grade_level_id' => $this->grade4->id, 'male' => 2, 'female' => 1, 'mixed' => 0],
            ['grade_level_id' => $this->grade5->id, 'male' => 1, 'female' => 0, 'mixed' => 0],
        ]))->assertOk();

        $response->assertJsonPath('data.created', 4)->assertJsonPath('data.placed', 1);
        $this->assertEqualsCanonicalizing(['4-B', '4-C', '8-A'], $response->json('data.unplaced')); // grade 5 has no code: its sort order (8) is used
        $this->assertSame(4, Classroom::count());
        $this->assertSame(3, Classroom::whereNull('room_id')->count());
    }

    public function test_a_room_hosts_one_classroom_per_year_and_other_years_can_use_it_again(): void
    {
        $this->floor('mixed', null, null, 1);
        $otherYear = AcademicYear::factory()->create();
        $this->actAsAdmin();
        $row = [['grade_level_id' => $this->grade4->id, 'male' => 0, 'female' => 0, 'mixed' => 1]];

        $this->postJson('/api/classrooms/generate', $this->plan($row))->assertOk()->assertJsonPath('data.placed', 1);
        $this->postJson('/api/classrooms/generate', ['academic_year_id' => $otherYear->id, 'plan' => $row])->assertOk()->assertJsonPath('data.placed', 1);
        $this->assertSame(1, Room::count());
    }

    public function test_the_plan_is_validated(): void
    {
        $this->actAsAdmin();
        $post = fn (array $plan) => $this->postJson('/api/classrooms/generate', $this->plan($plan));

        $post([['grade_level_id' => 999, 'male' => 1, 'female' => 0, 'mixed' => 0]])->assertUnprocessable()->assertJsonValidationErrors('plan.0.grade_level_id');
        $post([['grade_level_id' => $this->grade4->id, 'male' => -1, 'female' => 0, 'mixed' => 0]])->assertUnprocessable()->assertJsonValidationErrors('plan.0.male');
        $post([['grade_level_id' => $this->grade4->id, 'male' => 20, 'female' => 10, 'mixed' => 0]])->assertUnprocessable()->assertJsonValidationErrors('plan.0');
        $post([['grade_level_id' => $this->grade4->id, 'male' => 1, 'female' => 0, 'mixed' => 0], ['grade_level_id' => $this->grade4->id, 'male' => 1, 'female' => 0, 'mixed' => 0]])->assertUnprocessable();
        $post([])->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->postJson('/api/classrooms/generate', ['plan' => []])->assertUnprocessable()->assertJsonValidationErrors('academic_year_id');
        $this->assertSame(0, Classroom::count());
    }

    public function test_a_classroom_can_be_moved_to_another_room_but_not_to_a_taken_one(): void
    {
        $floor = $this->floor('mixed', null, null, 3);
        $science = Room::create(['floor_id' => $floor->id, 'name' => 'SL-0-1', 'kind' => 'science_lab', 'type' => 'lab']);
        [$a, $b, $c] = Room::where('floor_id', $floor->id)->where('kind', 'classroom')->orderBy('id')->get()->all();
        $one = Classroom::factory()->create(['academic_year_id' => $this->year->id, 'room_id' => $a->id]);
        Classroom::factory()->create(['academic_year_id' => $this->year->id, 'room_id' => $b->id]);
        $this->actAsAdmin();
        $body = fn (array $o) => [...['name' => $one->name, 'grade_level_id' => $one->grade_level_id, 'academic_year_id' => $this->year->id, 'gender' => 'female'], ...$o];

        $this->putJson("/api/classrooms/{$one->id}", $body(['room_id' => $c->id]))->assertOk()->assertJsonPath('data.room.id', $c->id)->assertJsonPath('data.gender', 'female');
        $this->putJson("/api/classrooms/{$one->id}", $body(['room_id' => $b->id]))->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->putJson("/api/classrooms/{$one->id}", $body(['room_id' => $science->id]))->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->putJson("/api/classrooms/{$one->id}", $body(['room_id' => $c->id]))->assertOk(); // its own room is not "taken"
        $this->putJson("/api/classrooms/{$one->id}", $body(['room_id' => null]))->assertOk()->assertJsonPath('data.room', null);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_are_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->postJson('/api/classrooms/generate', $this->plan([]))->assertForbidden();
    }
}
