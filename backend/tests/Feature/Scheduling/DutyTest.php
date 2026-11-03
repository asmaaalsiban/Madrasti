<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Modules\Scheduling\Models\DutyAssignment;
use App\Modules\Scheduling\Models\DutyPost;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Services\DutyScheduler;
use App\Modules\Scheduling\Services\PeriodSetup;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class DutyTest extends AcademicTestCase
{
    /** @var list<User> */
    private array $teachers = [];

    private DutyPost $gate;

    private DutyPost $yard;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-01-03 10:00:00');
        foreach (range(1, 5) as $i) {
            $this->teachers[] = User::factory()->staff(StaffType::Teacher)->create(['name' => "Teacher $i"]);
        }
        app(PeriodSetup::class)->apply(['days' => [0, 1, 2, 3, 4], 'periods_per_day' => 7, 'starts_at' => '07:45', 'period_minutes' => 45, 'gap_minutes' => 5, 'long_break_after' => 3, 'long_break_minutes' => 20]);
        $this->gate = DutyPost::create(['name' => 'Morning gate', 'starts_at' => '07:15:00', 'ends_at' => '07:40:00', 'staff_count' => 2, 'days' => null]);
        $this->yard = DutyPost::create(['name' => 'Yard at break', 'starts_at' => '10:10:00', 'ends_at' => '10:30:00', 'staff_count' => 1, 'days' => null]);
        $this->actAsAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function body(array $extra = []): array
    {
        return ['from' => '2027-01-03', 'to' => '2027-01-14', ...$extra]; // Sunday to Thursday, twice
    }

    public function test_it_gives_each_post_its_people_on_every_working_day_and_shares_the_duties_fairly(): void
    {
        $this->postJson('/api/duties/generate', $this->body())->assertOk()->assertJsonPath('data.duties', 30)->assertJsonPath('data.days', 10)->assertJsonPath('data.warnings', []); // 10 days x (2 + 1)
        $rows = DutyAssignment::all();
        $this->assertEmpty($rows->filter(fn ($d) => in_array($d->date->toDateString(), ['2027-01-08', '2027-01-09']))->all()); // no Friday or Saturday
        foreach ($rows->groupBy(fn ($d) => $d->date->toDateString()) as $day) {
            $this->assertSame($day->count(), $day->pluck('user_id')->unique()->count()); // nobody twice in a day
            $this->assertSame(2, $day->where('duty_post_id', $this->gate->id)->count());
        }
        $counts = $rows->countBy('user_id');
        $this->assertLessThanOrEqual(1, $counts->max() - $counts->min()); // 30 duties among 5 teachers: 6 each
        $this->assertSame('Morning gate', $rows->first()->location);
    }

    public function test_a_teacher_with_a_lesson_during_the_post_is_not_put_on_it(): void
    {
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $room = Classroom::factory()->create(['grade_level_id' => GradeLevel::factory()->create()->id, 'academic_year_id' => $year->id]);
        $subject = Subject::factory()->create();
        // Teacher 1 teaches period 1 (07:45-08:30) every day, which overlaps the gate (07:15-07:40)? No: ends before. Move the gate over it.
        $this->gate->update(['starts_at' => '07:30:00', 'ends_at' => '08:00:00']);
        foreach (Period::where('number', 1)->get() as $period) {
            DB::table('timetable_entries')->insert(['academic_year_id' => $year->id, 'classroom_id' => $room->id, 'period_id' => $period->id, 'subject_id' => $subject->id, 'teacher_id' => $this->teachers[0]->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->postJson('/api/duties/generate', $this->body())->assertOk();
        $this->assertSame(0, DutyAssignment::where('user_id', $this->teachers[0]->id)->where('duty_post_id', $this->gate->id)->count());
        $this->assertGreaterThan(0, DutyAssignment::where('user_id', $this->teachers[0]->id)->where('duty_post_id', $this->yard->id)->count()); // the yard is after period 1: fine
    }

    public function test_post_days_and_days_off_are_respected_and_too_few_people_is_a_warning(): void
    {
        $this->yard->update(['days' => [0, 2]]); // Sunday and Tuesday only
        $this->postJson('/api/duties/generate', $this->body(['exclude_dates' => ['2027-01-04']]))->assertOk();
        $this->assertSame(0, DutyAssignment::whereDate('date', '2027-01-04')->count());
        $yardDays = DutyAssignment::where('duty_post_id', $this->yard->id)->get()->map(fn ($d) => $d->date->dayOfWeek)->unique()->sort()->values()->all();
        $this->assertSame([0, 2], $yardDays);
        $this->gate->update(['staff_count' => 9]);
        $this->postJson('/api/duties/generate', $this->body(['dry_run' => true]))->assertOk()->assertJsonPath('data.dry_run', true)->assertJson(fn ($j) => $j->has('data.warnings.0')->etc());
        $this->postJson('/api/duties/generate', $this->body(['from' => '2027-01-08', 'to' => '2027-01-09']))->assertUnprocessable()->assertJsonValidationErrors('from'); // Friday and Saturday
        $this->gate->update(['is_active' => false]);
        $this->yard->update(['is_active' => false]);
        $this->postJson('/api/duties/generate', $this->body())->assertUnprocessable()->assertJsonValidationErrors('post_ids');
    }

    public function test_a_second_run_replaces_the_unlocked_duties_and_keeps_the_ones_set_by_hand(): void
    {
        $this->postJson('/api/duties/generate', $this->body(['from' => '2027-01-03', 'to' => '2027-01-04']))->assertOk();
        $first = DutyAssignment::where('duty_post_id', $this->yard->id)->whereDate('date', '2027-01-03')->first();
        $this->putJson("/api/duties/{$first->id}", ['user_id' => $this->teachers[4]->id])->assertOk()->assertJsonPath('data.is_locked', true); // set by hand
        $this->postJson('/api/duties/generate', $this->body(['from' => '2027-01-03', 'to' => '2027-01-04']))->assertOk()->assertJsonPath('data.duties', 5); // 6 minus the locked yard duty of Sunday
        $this->assertSame($this->teachers[4]->id, DutyAssignment::where('duty_post_id', $this->yard->id)->whereDate('date', '2027-01-03')->value('user_id'));
        $this->assertSame(6, DutyAssignment::count());
        $this->assertSame(1, DutyAssignment::where('is_locked', true)->count());
    }

    public function test_by_hand_a_duty_is_added_moved_and_removed_and_clashes_are_refused(): void
    {
        $id = $this->postJson('/api/duties', ['user_id' => $this->teachers[0]->id, 'duty_post_id' => $this->yard->id, 'date' => '2027-01-05'])->assertCreated()->assertJsonPath('data.location', 'Yard at break')->assertJsonPath('data.is_locked', true)->json('data.id');
        $this->postJson('/api/duties', ['user_id' => $this->teachers[0]->id, 'date' => '2027-01-05', 'starts_at' => '10:20', 'ends_at' => '10:40', 'location' => 'Corridor'])->assertUnprocessable()->assertJsonValidationErrors('user_id'); // overlaps the yard
        $this->postJson('/api/duties', ['user_id' => $this->teachers[0]->id, 'date' => '2027-01-05', 'starts_at' => '12:00', 'ends_at' => '12:30', 'location' => 'Corridor'])->assertCreated();
        $this->putJson("/api/duties/$id", ['starts_at' => '12:10', 'ends_at' => '12:20'])->assertUnprocessable(); // now inside the corridor duty
        $this->putJson("/api/duties/$id", ['date' => '2027-01-06', 'is_locked' => false])->assertOk()->assertJsonPath('data.is_locked', false);
        $this->postJson('/api/duties', ['user_id' => $this->teachers[0]->id, 'date' => '2027-01-05', 'starts_at' => '12:30', 'ends_at' => '12:00', 'location' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->deleteJson("/api/duties/$id")->assertNoContent();
        $this->assertNull(DutyAssignment::find($id));
    }

    public function test_posts_are_managed_and_a_deleted_post_leaves_its_past_duties(): void
    {
        $new = $this->postJson('/api/duties/posts', ['name' => 'Canteen', 'starts_at' => '09:00', 'ends_at' => '09:20', 'staff_count' => 2, 'days' => [1, 3]])->assertCreated()->assertJsonPath('data.days', [1, 3])->json('data.id');
        $this->postJson('/api/duties/posts', ['name' => 'Bad', 'starts_at' => '10:00', 'ends_at' => '09:00', 'staff_count' => 1])->assertUnprocessable();
        $this->putJson("/api/duties/posts/$new", ['name' => 'Canteen', 'starts_at' => '09:00', 'ends_at' => '09:20', 'staff_count' => 3, 'days' => []])->assertOk()->assertJsonPath('data.days', null)->assertJsonPath('data.staff_count', 3);
        $this->getJson('/api/duties/posts')->assertOk()->assertJsonCount(3, 'data');
        $this->postJson('/api/duties', ['user_id' => $this->teachers[0]->id, 'duty_post_id' => $new, 'date' => '2027-01-05'])->assertCreated();
        $this->deleteJson("/api/duties/posts/$new")->assertNoContent();
        $this->assertSame('Canteen', DutyAssignment::first()->location);
        $this->assertNull(DutyAssignment::first()->duty_post_id);
    }

    public function test_people_see_their_own_duties_get_told_and_reminded_the_day_before(): void
    {
        $this->postJson('/api/duties/generate', $this->body())->assertOk();
        $this->assertGreaterThan(0, DB::table('notifications')->where('notifiable_id', $this->teachers[0]->id)->where('data', 'like', '%duty_assigned%')->count());

        Sanctum::actingAs($this->teachers[0]);
        $mine = $this->getJson('/api/duties/me')->assertOk()->json('data');
        $this->assertSame(6, $mine['total']);
        $this->assertNotEmpty($mine['upcoming']);
        $this->assertSame([$this->teachers[0]->id], collect($mine['upcoming'])->pluck('user.id')->unique()->all());

        DB::table('notifications')->delete();
        $told = app(DutyScheduler::class)->remind('2027-01-04');
        $this->assertSame(DutyAssignment::whereDate('date', '2027-01-04')->pluck('user_id')->unique()->count(), $told);
        $this->assertSame(1, DB::table('notifications')->where('data', 'like', '%duty_reminder%')->where('notifiable_id', DutyAssignment::whereDate('date', '2027-01-04')->value('user_id'))->count());
    }

    public function test_only_the_principal_runs_the_roster(): void
    {
        $this->postJson('/api/duties/generate', $this->body())->assertOk();
        foreach ([$this->teachers[0], User::factory()->create(['role' => Role::Guardian])] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/duties')->assertForbidden();
            $this->postJson('/api/duties/generate', $this->body())->assertForbidden();
            $this->postJson('/api/duties/posts', ['name' => 'x', 'starts_at' => '09:00', 'ends_at' => '09:10', 'staff_count' => 1])->assertForbidden();
            $this->deleteJson('/api/duties/'.DutyAssignment::value('id'))->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->create(['role' => Role::Guardian]));
        $this->getJson('/api/duties/me')->assertForbidden();
    }
}
