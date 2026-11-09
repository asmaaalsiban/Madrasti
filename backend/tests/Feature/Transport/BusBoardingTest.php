<?php

namespace Tests\Feature\Transport;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class BusBoardingTest extends AcademicTestCase
{
    private User $admin;

    private User $escort;

    private User $driver;

    private int $bus;

    private int $morning;

    private int $evening;

    /** @var list<Student> */
    private array $kids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 07:00:00');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->escort = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Miss Huda']);
        $this->driver = User::factory()->staff(StaffType::Driver)->create(['name' => 'Ahmad']);
        Sanctum::actingAs($this->admin);
        $this->morning = $this->postJson('/api/transport/rounds', ['name_ar' => 'صباح', 'name_en' => 'Morning', 'period' => 'morning'])->json('data.id');
        $this->evening = $this->postJson('/api/transport/rounds', ['name_ar' => 'مساء', 'name_en' => 'Evening', 'period' => 'evening'])->json('data.id');
        $this->bus = $this->postJson('/api/transport/buses', ['number' => '7', 'capacity' => 20, 'area_from' => 'A', 'area_to' => 'B', 'driver_id' => $this->driver->id, 'round_ids' => [$this->morning, $this->evening], 'round_escorts' => [$this->morning => $this->escort->id, $this->evening => $this->escort->id]])->assertCreated()->json('data.id');
        foreach (['Sami', 'Lina', 'Omar'] as $i => $n) {
            $s = Student::create(['student_number' => "B-$i", 'name_ar' => $n, 'name_en' => $n, 'gender' => 'male']);
            $ride = ['pickup_round_id' => $this->morning, 'pickup_bus_id' => $this->bus];
            if ($n !== 'Omar') {
                $ride += ['dropoff_round_id' => $this->evening, 'dropoff_bus_id' => $this->bus]; // Omar only rides in the morning
            }
            $this->putJson("/api/transport/students/{$s->id}", $ride)->assertOk();
            $this->kids[] = $s;
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tick(Student $s, ?string $status, int $round, array $extra = [])
    {
        return $this->putJson('/api/transport/boarding', ['date' => '2026-11-10', 'bus_id' => $this->bus, 'round_id' => $round, 'student_id' => $s->id, 'status' => $status, ...$extra]);
    }

    public function test_the_escort_ticks_who_boarded_in_the_morning_round_and_again_in_the_evening_round_separately(): void
    {
        Sanctum::actingAs($this->escort);
        $this->tick($this->kids[0], 'boarded', $this->morning)->assertOk();
        $this->tick($this->kids[1], 'not_boarded', $this->morning)->assertOk();
        $this->tick($this->kids[1], 'boarded', $this->evening)->assertOk(); // Lina missed the morning bus but rides home

        $marks = collect($this->getJson('/api/transport/boarding/mine')->assertOk()->json('data.marks'));
        $this->assertCount(3, $marks);
        $this->assertSame('not_boarded', $marks->first(fn ($m) => $m['student_id'] === $this->kids[1]->id && $m['round_id'] === $this->morning)['status']);
        $this->assertSame('boarded', $marks->first(fn ($m) => $m['student_id'] === $this->kids[1]->id && $m['round_id'] === $this->evening)['status']);

        $this->tick($this->kids[1], 'boarded', $this->morning)->assertOk(); // she changes her mind: it is updated, not duplicated
        $this->assertSame(3, DB::table('bus_boardings')->count());
        $this->tick($this->kids[1], null, $this->morning)->assertOk();        // clear the tick
        $this->assertSame(2, DB::table('bus_boardings')->count());
    }

    public function test_nobody_is_notified(): void
    {
        $parent = User::factory()->role(Role::Guardian)->create();
        $this->kids[1]->guardians()->attach($parent->id);
        Sanctum::actingAs($this->escort);
        $this->tick($this->kids[1], 'not_boarded', $this->morning)->assertOk();
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_everyone_boarded_marks_only_those_without_a_tick(): void
    {
        Sanctum::actingAs($this->escort);
        $this->tick($this->kids[1], 'not_boarded', $this->morning)->assertOk();
        $this->putJson('/api/transport/boarding/all', ['date' => '2026-11-10', 'bus_id' => $this->bus, 'round_id' => $this->morning])->assertOk()->assertJsonPath('data.marked', 2);
        $this->assertSame('not_boarded', DB::table('bus_boardings')->where('student_id', $this->kids[1]->id)->value('status')); // her tick stays
        $this->assertSame(3, DB::table('bus_boardings')->count());
    }

    public function test_only_the_buss_escort_or_driver_and_only_for_a_student_on_that_round(): void
    {
        $stranger = User::factory()->staff(StaffType::Teacher)->create();
        Sanctum::actingAs($stranger);
        $this->tick($this->kids[0], 'boarded', $this->morning)->assertForbidden();

        Sanctum::actingAs($this->driver); // the driver may tick too
        $this->tick($this->kids[0], 'boarded', $this->morning)->assertOk();

        Sanctum::actingAs($this->escort);
        $this->tick($this->kids[2], 'boarded', $this->evening)->assertStatus(422)->assertJsonValidationErrors('student_id'); // Omar has no evening ride
        $this->tick($this->kids[0], 'maybe', $this->morning)->assertStatus(422);
    }

    public function test_the_escort_of_the_morning_round_cannot_tick_the_evening_round_of_the_same_bus(): void
    {
        $evening = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Miss Rana']);
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/transport/buses/{$this->bus}", ['round_escorts' => [$this->evening => $evening->id]])->assertOk();

        Sanctum::actingAs($this->escort);
        $this->tick($this->kids[0], 'boarded', $this->morning)->assertOk();
        $this->tick($this->kids[0], 'boarded', $this->evening)->assertForbidden();
        Sanctum::actingAs($evening);
        $this->tick($this->kids[0], 'boarded', $this->evening)->assertOk();
        $this->tick($this->kids[0], 'boarded', $this->morning)->assertForbidden();
    }

    public function test_a_stand_in_escort_replaces_the_usual_one_for_that_day_only(): void
    {
        $stand = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Miss Rana']);
        Sanctum::actingAs($this->admin);
        $today = now()->toDateString();
        $this->putJson('/api/transport/substitutes', ['date' => $today, 'bus_id' => $this->bus, 'round_id' => $this->morning, 'escort_id' => $stand->id])->assertOk();
        $this->getJson('/api/transport')->assertOk()->assertJsonPath('substitutes.0.escort.name', 'Miss Rana')
            ->assertJsonPath('buses.0.round_escorts.'.$this->morning.'.name', 'Miss Huda'); // the usual escort stays as set

        Sanctum::actingAs($stand);
        $this->getJson('/api/auth/me')->assertJsonPath('data.bus_duty', ['escort']);
        $this->assertCount(1, $this->getJson('/api/transport/mine')->json('data.0.rounds'));
        $this->tick($this->kids[0], 'boarded', $this->morning)->assertOk();
        $this->tick($this->kids[0], 'boarded', $this->evening)->assertForbidden();

        Sanctum::actingAs($this->escort); // absent today for the morning round, still on duty for the evening one
        $this->tick($this->kids[0], 'not_boarded', $this->morning)->assertForbidden();
        $this->tick($this->kids[0], 'boarded', $this->evening)->assertOk();

        Sanctum::actingAs($this->admin); // removing the stand-in brings the usual escort back
        $this->putJson('/api/transport/substitutes', ['date' => $today, 'bus_id' => $this->bus, 'round_id' => $this->morning, 'escort_id' => null])->assertOk();
        Sanctum::actingAs($this->escort);
        $this->tick($this->kids[0], 'not_boarded', $this->morning)->assertOk();
    }

    public function test_a_stand_in_needs_a_future_date_a_round_of_the_bus_and_the_right_person(): void
    {
        Sanctum::actingAs($this->admin);
        $ok = ['date' => now()->addDay()->toDateString(), 'bus_id' => $this->bus, 'round_id' => $this->morning, 'escort_id' => $this->escort->id];
        $this->putJson('/api/transport/substitutes', [...$ok, 'date' => now()->subDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->putJson('/api/transport/substitutes', [...$ok, 'escort_id' => $this->driver->id])->assertStatus(422);
        $other = $this->postJson('/api/transport/rounds', ['name_ar' => 'x', 'name_en' => 'X', 'period' => 'morning'])->json('data.id');
        $this->putJson('/api/transport/substitutes', [...$ok, 'round_id' => $other])->assertStatus(422)->assertJsonValidationErrors('round_id');
        $this->putJson('/api/transport/substitutes', $ok)->assertOk();
        Sanctum::actingAs($this->escort);
        $this->putJson('/api/transport/substitutes', $ok)->assertForbidden();
    }

    public function test_the_escort_can_tick_today_only_and_the_admin_can_correct_any_day_and_sees_the_report(): void
    {
        Sanctum::actingAs($this->escort);
        $this->tick($this->kids[0], 'boarded', $this->morning, ['date' => '2026-11-09'])->assertStatus(422)->assertJsonValidationErrors('date');

        Sanctum::actingAs($this->admin);
        $this->tick($this->kids[0], 'boarded', $this->morning, ['date' => '2026-11-09'])->assertOk();

        Sanctum::actingAs($this->escort);
        $this->tick($this->kids[0], 'boarded', $this->morning)->assertOk();
        $this->tick($this->kids[1], 'not_boarded', $this->morning)->assertOk();
        Sanctum::actingAs($this->admin);
        $row = collect($this->getJson('/api/transport/boarding/report?date=2026-11-10')->assertOk()->json('data.rows'))->firstWhere('round.id', $this->morning);
        $this->assertSame([3, 1, 1, 1], [$row['total'], $row['boarded'], $row['not_boarded'], $row['not_ticked']]);
        $this->assertSame('Lina', $row['missed'][0]['name_en']);

        Sanctum::actingAs($this->escort);
        $this->getJson('/api/transport/boarding/report')->assertForbidden();
    }
}
