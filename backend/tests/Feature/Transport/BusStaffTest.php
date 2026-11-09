<?php

namespace Tests\Feature\Transport;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class BusStaffTest extends AcademicTestCase
{
    private User $admin;

    private User $driver;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->driver = User::factory()->staff(StaffType::Driver)->create(['name' => 'Ahmad Al-Sultani', 'phone' => '+962790001111']);
        $this->teacher = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Miss Huda', 'phone' => '+962790002222']);
        Sanctum::actingAs($this->admin);
    }

    private function round(string $period = 'morning'): int
    {
        return $this->postJson('/api/transport/rounds', ['name_ar' => 'جولة', 'name_en' => 'Round', 'period' => $period])->assertCreated()->json('data.id');
    }

    private function bus(array $extra = []): int
    {
        return $this->postJson('/api/transport/buses', ['number' => '7', 'capacity' => 20, 'area_from' => 'Downtown', 'area_to' => 'School', ...$extra])->assertCreated()->json('data.id');
    }

    public function test_the_driver_and_the_escort_are_chosen_from_the_staff_and_the_bus_shows_them(): void
    {
        $response = $this->postJson('/api/transport/buses', ['number' => '7', 'capacity' => 20, 'area_from' => 'A', 'area_to' => 'B', 'driver_id' => $this->driver->id, 'round_ids' => [$r = $this->round()], 'round_escorts' => [$r => $this->teacher->id]])->assertCreated();

        $response->assertJsonPath('data.driver.name', 'Ahmad Al-Sultani')->assertJsonPath('data.driver.phone', '+962790001111')->assertJsonPath("data.round_escorts.$r.name", 'Miss Huda')
            ->assertJsonPath('data.driver_name', 'Ahmad Al-Sultani'); // every old screen keeps working

        $overview = $this->getJson('/api/transport')->assertOk();
        $overview->assertJsonPath('drivers.0.name', 'Ahmad Al-Sultani')->assertJsonCount(1, 'drivers');
        $this->assertContains('Miss Huda', array_column($overview->json('escorts'), 'name'));
        $this->assertNotContains('Ahmad Al-Sultani', array_column($overview->json('escorts'), 'name')); // a driver is not an escort
    }

    public function test_a_driver_not_in_the_staff_can_still_be_typed_by_hand(): void
    {
        $this->postJson('/api/transport/buses', ['number' => '8', 'capacity' => 20, 'area_from' => 'A', 'area_to' => 'B', 'driver_name' => 'Abu Ali', 'driver_phone' => '0799'])->assertCreated()->assertJsonPath('data.driver.name', 'Abu Ali')->assertJsonPath('data.driver.id', null);
        $this->postJson('/api/transport/buses', ['number' => '9', 'capacity' => 20, 'area_from' => 'A', 'area_to' => 'B'])->assertUnprocessable()->assertJsonValidationErrors('driver_name');
    }

    public function test_only_the_right_people_can_be_chosen(): void
    {
        $base = ['number' => '7', 'capacity' => 20, 'area_from' => 'A', 'area_to' => 'B'];
        $r = $this->round();
        $this->postJson('/api/transport/buses', $base + ['driver_id' => $this->teacher->id])->assertUnprocessable()->assertJsonValidationErrors('driver_id');           // a teacher is not a driver
        $this->postJson('/api/transport/buses', $base + ['driver_name' => 'X', 'round_ids' => [$r], 'round_escorts' => [$r => User::factory()->staff(StaffType::Accountant)->create()->id]])->assertUnprocessable()->assertJsonValidationErrors('round_escorts.'.$r);
        $this->postJson('/api/transport/buses', $base + ['driver_id' => $this->driver->id, 'round_ids' => [$r], 'round_escorts' => [$r => $this->driver->id]])->assertUnprocessable();                   // not the same person
        $this->postJson('/api/transport/buses', $base + ['driver_name' => 'X', 'round_ids' => [$r], 'round_escorts' => [$r => User::factory()->role(Role::Guardian)->create()->id]])->assertUnprocessable(); // a parent is not staff
        $this->driver->update(['is_active' => false]);
        $this->postJson('/api/transport/buses', $base + ['driver_id' => $this->driver->id])->assertUnprocessable();                                                    // inactive
    }

    public function test_the_driver_sees_his_bus_rounds_students_parents_and_the_escort_and_the_escort_sees_hers(): void
    {
        $morning = $this->round();
        $evening = $this->round('evening');
        $bus = $this->bus(['driver_id' => $this->driver->id, 'round_ids' => [$morning, $evening], 'round_escorts' => [$morning => $this->teacher->id]]);
        $other = $this->bus(['number' => '9', 'driver_name' => 'Someone Else', 'round_ids' => [$morning]]);
        $sara = Student::create(['student_number' => 'B-1', 'name_ar' => 'سارة', 'name_en' => 'Sara', 'gender' => 'female']);
        $omar = Student::create(['student_number' => 'B-2', 'name_ar' => 'عمر', 'name_en' => 'Omar', 'gender' => 'male']);
        $mum = User::factory()->role(Role::Guardian)->create(['name' => 'Mum Sara', 'phone' => '+962795550000']);
        $sara->guardians()->attach($mum->id, ['is_primary' => true]);
        $this->putJson("/api/transport/students/{$sara->id}", ['pickup_round_id' => $morning, 'pickup_bus_id' => $bus, 'dropoff_round_id' => $evening, 'dropoff_bus_id' => $bus])->assertOk();
        $this->putJson("/api/transport/students/{$omar->id}", ['pickup_round_id' => $morning, 'pickup_bus_id' => $other])->assertOk(); // on another bus

        Sanctum::actingAs($this->driver);
        $mine = $this->getJson('/api/transport/mine')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('7', $mine['number']);
        $this->assertSame('driver', $mine['my_part']);
        $this->assertSame('Miss Huda', $mine['rounds'][0]['escort']['name']);
        $this->assertNull($mine['rounds'][1]['escort']);
        $this->assertCount(2, $mine['rounds']);
        $this->assertSame('morning', $mine['rounds'][0]['period']);
        $this->assertSame(['B-1'], array_column($mine['rounds'][0]['students'], 'student_number'));   // only his students
        $this->assertSame('Mum Sara', $mine['rounds'][0]['students'][0]['parent_name']);
        $this->assertSame('+962795550000', $mine['rounds'][0]['students'][0]['parent_phone']);
        $this->assertSame(['B-1'], array_column($mine['rounds'][1]['students'], 'student_number'));   // and coming back in the evening round

        Sanctum::actingAs($this->teacher);
        $escort = $this->getJson('/api/transport/mine')->assertOk()->assertJsonPath('data.0.my_part', 'escort')->assertJsonPath('data.0.driver.name', 'Ahmad Al-Sultani')->json('data.0');
        $this->assertSame('7', $escort['number']);
        $this->assertCount(1, $escort['rounds']); // only the round she escorts, not the evening one

        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->getJson('/api/transport/mine')->assertOk()->assertJsonCount(0, 'data'); // nobody else sees a bus
    }

    public function test_the_app_knows_who_has_bus_duty_and_a_parent_sees_the_escort_of_the_childs_bus(): void
    {
        $morning = $this->round();
        $bus = $this->bus(['driver_id' => $this->driver->id, 'round_ids' => [$morning], 'round_escorts' => [$morning => $this->teacher->id]]);
        $kid = Student::create(['student_number' => 'B-3', 'name_ar' => 'ليلى', 'name_en' => 'Layla', 'gender' => 'female']);
        $mum = User::factory()->role(Role::Guardian)->create();
        $kid->guardians()->attach($mum->id, ['is_primary' => true]);
        $this->putJson("/api/transport/students/{$kid->id}", ['pickup_round_id' => $morning, 'pickup_bus_id' => $bus])->assertOk();

        Sanctum::actingAs($this->driver);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.bus_duty', ['driver']);
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/auth/me')->assertJsonPath('data.bus_duty', ['escort']);
        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->getJson('/api/auth/me')->assertJsonPath('data.bus_duty', []);

        Sanctum::actingAs($mum);
        $this->getJson("/api/students/{$kid->id}/transport")->assertOk()->assertJsonPath('data.pickup_bus.escort_name', 'Miss Huda')->assertJsonPath('data.pickup_bus.driver_name', 'Ahmad Al-Sultani');
    }

    public function test_changing_the_driver_on_an_existing_bus_updates_the_name_and_phone(): void
    {
        $bus = $this->bus(['driver_name' => 'Abu Ali']);
        $this->putJson("/api/transport/buses/$bus", ['driver_id' => $this->driver->id])->assertOk()->assertJsonPath('data.driver_name', 'Ahmad Al-Sultani')->assertJsonPath('data.driver.phone', '+962790001111');
        $this->putJson("/api/transport/buses/$bus", ['driver_id' => null])->assertOk();
    }

    public function test_each_round_of_a_bus_has_its_own_escort_and_only_that_escort_can_tick_it(): void
    {
        $m1 = $this->round();
        $m2 = $this->round();
        $e1 = $this->round('evening');
        $second = User::factory()->staff(StaffType::BusSupervisor)->create(['name' => 'Miss Rana']);
        $bus = $this->bus(['driver_id' => $this->driver->id, 'round_ids' => [$m1, $m2, $e1], 'round_escorts' => [$m1 => $this->teacher->id, $m2 => $second->id, $e1 => $this->teacher->id]]);

        $this->getJson('/api/transport')->assertOk()->assertJsonPath('buses.0.round_escorts.'.$m1.'.name', 'Miss Huda')->assertJsonPath('buses.0.round_escorts.'.$m2.'.name', 'Miss Rana');

        // Changing one round's escort leaves the others; sending only round_ids keeps the escorts of the rounds that stay.
        $this->putJson("/api/transport/buses/$bus", ['round_escorts' => [$m2 => $this->teacher->id]])->assertOk()->assertJsonPath('data.round_escorts.'.$m2.'.name', 'Miss Huda');
        $this->putJson("/api/transport/buses/$bus", ['round_ids' => [$m1, $m2]])->assertOk()->assertJsonPath('data.round_escorts.'.$m1.'.name', 'Miss Huda');
        $this->putJson("/api/transport/buses/$bus", ['round_escorts' => [$m1 => null]])->assertOk()->assertJsonPath('data.round_escorts.'.$m1, null);
        $this->putJson("/api/transport/buses/$bus", ['round_escorts' => [$e1 => $second->id]])->assertUnprocessable()->assertJsonValidationErrors('round_escorts'); // a round the bus does not serve

        Sanctum::actingAs($second);
        $this->getJson('/api/auth/me')->assertJsonPath('data.bus_duty', []);   // no round left for her
        Sanctum::actingAs($this->teacher);
        $this->assertCount(1, $this->getJson('/api/transport/mine')->json('data.0.rounds'));
    }
}
