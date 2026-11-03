<?php

namespace Tests\Feature\Transport;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Transport\Models\StudentTransport;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class TransportTest extends AcademicTestCase
{
    private User $admin;

    private function round(string $ar = 'الأولى', string $en = 'First', string $period = 'morning', array $extra = []): int
    {
        return $this->postJson('/api/transport/rounds', ['name_ar' => $ar, 'name_en' => $en, 'period' => $period, ...$extra])->assertCreated()->json('data.id');
    }

    private function bus(string $number, array $rounds, array $extra = []): int
    {
        return $this->postJson('/api/transport/buses', ['number' => $number, 'driver_name' => 'Abu Ali', 'capacity' => 2, 'area_from' => 'Downtown', 'area_to' => 'School', 'round_ids' => $rounds, ...$extra])->assertCreated()->json('data.id');
    }

    private function student(string $n): Student
    {
        return Student::create(['student_number' => "T-$n", 'name_ar' => "طالب $n", 'name_en' => "Student $n", 'gender' => 'male']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->role(Role::Admin)->create();
        Sanctum::actingAs($this->admin);
    }

    public function test_a_school_can_have_as_many_rounds_as_it_needs(): void
    {
        foreach (['1', '2', '3', '4', '5'] as $n) {
            $this->round("جولة $n", "Round $n");
        }
        $this->getJson('/api/transport')->assertOk()->assertJsonCount(5, 'rounds');
    }

    public function test_a_student_rides_in_a_morning_round_and_comes_back_in_an_evening_round_on_the_same_or_another_bus(): void
    {
        $m1 = $this->round('صباحية 1', 'Morning 1');
        $e2 = $this->round('مسائية 2', 'Evening 2', 'evening');
        $b11 = $this->bus('11', [$m1, $e2]);
        $b12 = $this->bus('12', [$e2]);
        $s = $this->student('one');
        $this->putJson("/api/transport/students/{$s->id}", ['pickup_round_id' => $m1, 'pickup_bus_id' => $b11, 'dropoff_round_id' => $e2, 'dropoff_bus_id' => $b11])->assertOk()
            ->assertJsonPath('data.pickup_bus.number', '11')->assertJsonPath('data.dropoff_bus.number', '11')->assertJsonPath('data.pickup_round.period', 'morning')->assertJsonPath('data.dropoff_round.period', 'evening');
        $this->putJson("/api/transport/students/{$s->id}", ['pickup_round_id' => $m1, 'pickup_bus_id' => $b11, 'dropoff_round_id' => $e2, 'dropoff_bus_id' => $b12])->assertOk()->assertJsonPath('data.dropoff_bus.number', '12');
        $this->putJson("/api/transport/students/{$s->id}", ['pickup_round_id' => $m1, 'pickup_bus_id' => $b11])->assertOk()->assertJsonPath('data.dropoff_bus', null)->assertJsonPath('data.dropoff_round', null);
        $this->putJson("/api/transport/students/{$s->id}", [])->assertStatus(422);
        $this->putJson("/api/transport/students/{$s->id}", ['pickup_bus_id' => $b11])->assertStatus(422);
        $this->assertSame(1, StudentTransport::count());
    }

    public function test_the_way_to_school_needs_a_morning_round_and_the_way_home_an_evening_round(): void
    {
        $m1 = $this->round();
        $e1 = $this->round('مسائية', 'Evening', 'evening');
        $bus = $this->bus('1', [$m1, $e1]);
        $s = $this->student('one');
        $this->putJson("/api/transport/students/{$s->id}", ['pickup_round_id' => $e1, 'pickup_bus_id' => $bus])->assertStatus(422)->assertJsonValidationErrors('pickup_round_id');
        $this->putJson("/api/transport/students/{$s->id}", ['dropoff_round_id' => $m1, 'dropoff_bus_id' => $bus])->assertStatus(422)->assertJsonValidationErrors('dropoff_round_id');
    }

    public function test_choosing_a_bus_for_a_round_it_did_not_serve_adds_the_round_to_the_bus_and_seats_are_limited(): void
    {
        $r1 = $this->round();
        $r2 = $this->round('الثانية', 'Second');
        $bus = $this->bus('1', [$r1]);
        $x = $this->student('x');
        $this->putJson("/api/transport/students/{$x->id}", ['pickup_round_id' => $r2, 'pickup_bus_id' => $bus])->assertOk();
        $this->getJson('/api/transport')->assertJsonPath('buses.0.round_ids', [$r1, $r2]);
        $this->deleteJson("/api/transport/students/{$x->id}")->assertNoContent();
        foreach (['a', 'b'] as $n) {
            $this->putJson('/api/transport/students/'.$this->student($n)->id, ['pickup_round_id' => $r1, 'pickup_bus_id' => $bus])->assertOk();
        }
        $this->putJson("/api/transport/students/{$x->id}", ['pickup_round_id' => $r1, 'pickup_bus_id' => $bus])->assertStatus(422)->assertJsonValidationErrors('pickup_bus_id');
        // The same bus is not full in the other round.
        $this->putJson("/api/transport/students/{$x->id}", ['pickup_round_id' => $r2, 'pickup_bus_id' => $bus])->assertOk();
        $this->getJson('/api/transport')->assertJsonPath("buses.0.load.$r1", 2)->assertJsonPath("buses.0.load.$r2", 1);
    }

    public function test_a_rider_can_be_updated_without_counting_their_own_seat_and_removed(): void
    {
        $r1 = $this->round();
        $bus = $this->bus('1', [$r1], ['capacity' => 1]);
        $s = $this->student('one');
        $this->putJson("/api/transport/students/{$s->id}", ['pickup_round_id' => $r1, 'pickup_bus_id' => $bus])->assertOk();
        $this->putJson("/api/transport/students/{$s->id}", ['pickup_round_id' => $r1, 'pickup_bus_id' => $bus])->assertOk();
        $this->deleteJson("/api/transport/students/{$s->id}")->assertNoContent();
        $this->assertSame(0, StudentTransport::count());
    }

    public function test_rounds_and_buses_in_use_cannot_be_removed_or_shrunk(): void
    {
        $r1 = $this->round();
        $r2 = $this->round('الثانية', 'Second');
        $bus = $this->bus('1', [$r1, $r2]);
        $s = $this->student('one');
        $this->putJson("/api/transport/students/{$s->id}", ['pickup_round_id' => $r1, 'pickup_bus_id' => $bus])->assertOk();
        $this->deleteJson("/api/transport/rounds/$r1")->assertStatus(422);
        $this->putJson("/api/transport/rounds/$r1", ['name_ar' => 'x', 'name_en' => 'x', 'period' => 'evening'])->assertStatus(422);
        $this->putJson("/api/transport/rounds/$r1", ['name_ar' => 'جديد', 'name_en' => 'New', 'period' => 'morning'])->assertOk();
        $this->deleteJson("/api/transport/buses/$bus")->assertStatus(422);
        $this->putJson("/api/transport/buses/$bus", ['round_ids' => [$r2]])->assertStatus(422);
        $this->putJson("/api/transport/buses/$bus", ['capacity' => 1, 'driver_name' => 'New driver'])->assertOk()->assertJsonPath('data.driver_name', 'New driver');
        $this->putJson("/api/transport/buses/$bus", ['round_ids' => [$r1]])->assertOk();
        $this->deleteJson("/api/transport/rounds/$r2")->assertNoContent();
    }

    public function test_bus_numbers_are_unique_and_the_riders_of_a_bus_are_listed_per_way(): void
    {
        $m1 = $this->round();
        $e1 = $this->round('مسائية', 'Evening', 'evening');
        $bus = $this->bus('7', [$m1, $e1]);
        $this->postJson('/api/transport/buses', ['number' => '7', 'driver_name' => 'X', 'capacity' => 10, 'area_from' => 'a', 'area_to' => 'b'])->assertStatus(422);
        $s = $this->student('one');
        $this->putJson("/api/transport/students/{$s->id}", ['pickup_round_id' => $m1, 'pickup_bus_id' => $bus, 'dropoff_round_id' => $e1, 'dropoff_bus_id' => $bus])->assertOk();
        $this->getJson("/api/transport/buses/$bus/riders")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.student.name_en', 'Student one')->assertJsonPath('data.0.direction', 'pickup')->assertJsonPath('data.1.direction', 'dropoff');
        $this->getJson('/api/transport/assignments?only=riding')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/transport/assignments?only=none')->assertOk()->assertJsonCount(0, 'data');
        $this->assertTrue(AuditLog::where('action', 'created')->exists());
    }

    public function test_only_the_principal_manages_and_a_family_reads_only_its_own_child(): void
    {
        $r1 = $this->round();
        $bus = $this->bus('1', [$r1]);
        $mine = $this->student('mine');
        $other = $this->student('other');
        $this->putJson("/api/transport/students/{$mine->id}", ['pickup_round_id' => $r1, 'pickup_bus_id' => $bus])->assertOk();

        $parent = User::factory()->role(Role::Guardian)->create();
        $mine->guardians()->attach($parent->id);
        $teacher = User::factory()->staff(StaffType::Teacher)->create();

        Sanctum::actingAs($parent);
        $this->getJson("/api/students/{$mine->id}/transport")->assertOk()->assertJsonPath('data.pickup_bus.number', '1');
        $this->getJson("/api/students/{$other->id}/transport")->assertForbidden();
        $this->getJson('/api/transport')->assertForbidden();
        $this->putJson("/api/transport/students/{$mine->id}", ['pickup_round_id' => $r1, 'pickup_bus_id' => $bus])->assertForbidden();

        Sanctum::actingAs($teacher);
        $this->getJson('/api/transport')->assertForbidden();
        $this->getJson("/api/students/{$mine->id}/transport")->assertForbidden();
    }
}
