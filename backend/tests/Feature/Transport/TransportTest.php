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

    private function round(string $ar = 'الأولى', string $en = 'First', array $extra = []): int
    {
        return $this->postJson('/api/transport/rounds', ['name_ar' => $ar, 'name_en' => $en, ...$extra])->assertCreated()->json('data.id');
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

    public function test_a_school_has_at_most_three_rounds(): void
    {
        foreach (['1', '2', '3'] as $n) {
            $this->round("جولة $n", "Round $n");
        }
        $this->postJson('/api/transport/rounds', ['name_ar' => 'رابعة', 'name_en' => 'Fourth'])->assertStatus(422);
        $this->getJson('/api/transport')->assertOk()->assertJsonCount(3, 'rounds');
    }

    public function test_a_student_can_have_a_different_bus_to_school_and_home_or_just_one(): void
    {
        $r1 = $this->round();
        $a = $this->bus('1', [$r1]);
        $b = $this->bus('2', [$r1]);
        $s = $this->student('one');
        $this->putJson("/api/transport/students/{$s->id}", ['round_id' => $r1, 'pickup_bus_id' => $a, 'dropoff_bus_id' => $b])->assertOk()
            ->assertJsonPath('data.pickup_bus.number', '1')->assertJsonPath('data.dropoff_bus.number', '2');
        $this->putJson("/api/transport/students/{$s->id}", ['round_id' => $r1, 'pickup_bus_id' => $a])->assertOk()->assertJsonPath('data.dropoff_bus', null);
        $this->putJson("/api/transport/students/{$s->id}", ['round_id' => $r1])->assertStatus(422);
        $this->assertSame(1, StudentTransport::count());
    }

    public function test_a_bus_only_takes_students_of_the_rounds_it_serves_and_only_up_to_its_seats(): void
    {
        $r1 = $this->round();
        $r2 = $this->round('الثانية', 'Second');
        $bus = $this->bus('1', [$r1]);
        $x = $this->student('x');
        $this->putJson("/api/transport/students/{$x->id}", ['round_id' => $r2, 'pickup_bus_id' => $bus])->assertStatus(422)->assertJsonValidationErrors('pickup_bus_id');
        foreach (['a', 'b'] as $n) {
            $this->putJson('/api/transport/students/'.$this->student($n)->id, ['round_id' => $r1, 'pickup_bus_id' => $bus])->assertOk();
        }
        // Full going to school, but the same bus still has seats for the way home.
        $this->putJson("/api/transport/students/{$x->id}", ['round_id' => $r1, 'pickup_bus_id' => $bus])->assertStatus(422);
        $this->putJson("/api/transport/students/{$x->id}", ['round_id' => $r1, 'dropoff_bus_id' => $bus])->assertOk();
        $this->getJson('/api/transport')->assertJsonPath("buses.0.load.$r1.pickup", 2)->assertJsonPath("buses.0.load.$r1.dropoff", 1);
    }

    public function test_a_rider_can_be_updated_without_counting_their_own_seat_and_removed(): void
    {
        $r1 = $this->round();
        $bus = $this->bus('1', [$r1], ['capacity' => 1]);
        $s = $this->student('one');
        $this->putJson("/api/transport/students/{$s->id}", ['round_id' => $r1, 'pickup_bus_id' => $bus])->assertOk();
        $this->putJson("/api/transport/students/{$s->id}", ['round_id' => $r1, 'pickup_bus_id' => $bus, 'dropoff_bus_id' => $bus])->assertOk();
        $this->deleteJson("/api/transport/students/{$s->id}")->assertNoContent();
        $this->assertSame(0, StudentTransport::count());
    }

    public function test_rounds_and_buses_in_use_cannot_be_removed_or_shrunk(): void
    {
        $r1 = $this->round();
        $r2 = $this->round('الثانية', 'Second');
        $bus = $this->bus('1', [$r1, $r2]);
        $s = $this->student('one');
        $this->putJson("/api/transport/students/{$s->id}", ['round_id' => $r1, 'pickup_bus_id' => $bus])->assertOk();
        $this->deleteJson("/api/transport/rounds/$r1")->assertStatus(422);
        $this->deleteJson("/api/transport/buses/$bus")->assertStatus(422);
        $this->putJson("/api/transport/buses/$bus", ['round_ids' => [$r2]])->assertStatus(422);
        $this->putJson("/api/transport/buses/$bus", ['capacity' => 1, 'driver_name' => 'New driver'])->assertOk()->assertJsonPath('data.driver_name', 'New driver');
        $this->putJson("/api/transport/buses/$bus", ['round_ids' => [$r1]])->assertOk();
        $this->deleteJson("/api/transport/rounds/$r2")->assertNoContent();
    }

    public function test_bus_numbers_are_unique_and_the_riders_of_a_bus_are_listed(): void
    {
        $r1 = $this->round();
        $bus = $this->bus('7', [$r1]);
        $this->postJson('/api/transport/buses', ['number' => '7', 'driver_name' => 'X', 'capacity' => 10, 'area_from' => 'a', 'area_to' => 'b'])->assertStatus(422);
        $s = $this->student('one');
        $this->putJson("/api/transport/students/{$s->id}", ['round_id' => $r1, 'dropoff_bus_id' => $bus])->assertOk();
        $this->getJson("/api/transport/buses/$bus/riders")->assertOk()->assertJsonPath('data.0.student.name_en', 'Student one')->assertJsonPath('data.0.dropoff', true)->assertJsonPath('data.0.pickup', false);
        $this->getJson('/api/transport/assignments?only=riding')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/transport/assignments?only=none')->assertJsonCount(0, 'data');
        $this->assertTrue(AuditLog::where('action', 'created')->exists());
    }

    public function test_only_the_principal_manages_and_a_family_reads_only_its_own_child(): void
    {
        $r1 = $this->round();
        $bus = $this->bus('1', [$r1]);
        $mine = $this->student('mine');
        $other = $this->student('other');
        $this->putJson("/api/transport/students/{$mine->id}", ['round_id' => $r1, 'pickup_bus_id' => $bus])->assertOk();

        $parent = User::factory()->role(Role::Guardian)->create();
        $mine->guardians()->attach($parent->id);
        $teacher = User::factory()->staff(StaffType::Teacher)->create();

        Sanctum::actingAs($parent);
        $this->getJson("/api/students/{$mine->id}/transport")->assertOk()->assertJsonPath('data.pickup_bus.number', '1');
        $this->getJson("/api/students/{$other->id}/transport")->assertForbidden();
        $this->getJson('/api/transport')->assertForbidden();
        $this->putJson("/api/transport/students/{$mine->id}", ['round_id' => $r1, 'pickup_bus_id' => $bus])->assertForbidden();

        Sanctum::actingAs($teacher);
        $this->getJson('/api/transport')->assertForbidden();
        $this->getJson("/api/students/{$mine->id}/transport")->assertForbidden();
    }
}
