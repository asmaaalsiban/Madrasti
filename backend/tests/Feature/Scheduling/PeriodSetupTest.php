<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Subject;
use App\Modules\Scheduling\Models\Period;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Academic\AcademicTestCase;

class PeriodSetupTest extends AcademicTestCase
{
    private function body(array $override = []): array
    {
        return [...[
            'days' => [0, 1, 2, 3, 4], 'periods_per_day' => 7, 'starts_at' => '07:45', 'period_minutes' => 45, 'gap_minutes' => 5,
            'long_break_after' => 3, 'long_break_minutes' => 20,
        ], ...$override];
    }

    public function test_it_creates_the_periods_of_every_school_day_with_the_breaks_between_them(): void
    {
        $this->actAsAdmin();

        $this->putJson('/api/periods/setup', $this->body())->assertOk()->assertJsonCount(35, 'data.periods');

        $this->assertSame(35, Period::count());
        $sunday = Period::where('day_of_week', 0)->orderBy('number')->get();
        $this->assertSame(
            ['07:45-08:30', '08:35-09:20', '09:25-10:10', '10:30-11:15', '11:20-12:05', '12:10-12:55', '13:00-13:45'],
            $sunday->map(fn ($p) => substr($p->starts_at, 0, 5).'-'.substr($p->ends_at, 0, 5))->all(),
        );
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $sunday->pluck('number')->all());
        $this->assertSame([0, 1, 2, 3, 4], Period::distinct()->orderBy('day_of_week')->pluck('day_of_week')->all());
    }

    public function test_the_setup_is_returned_with_the_periods(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/periods/setup', $this->body(['days' => [0, 1], 'periods_per_day' => 3, 'long_break_after' => null]))->assertOk();

        $data = $this->getJson('/api/periods')->assertOk()->json('data');

        $this->assertSame([0, 1], $data['setup']['days']);
        $this->assertSame(3, $data['setup']['periods_per_day']);
        $this->assertSame('07:45', $data['setup']['starts_at']);
        $this->assertNull($data['setup']['long_break_after']);
        $this->assertCount(6, $data['periods']);
        $this->assertSame(['day_of_week' => 0, 'number' => 1, 'starts_at' => '07:45', 'ends_at' => '08:30'], collect($data['periods'][0])->only(['day_of_week', 'number', 'starts_at', 'ends_at'])->all());
    }

    public function test_changing_the_setup_updates_the_times_and_removes_unused_periods(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/periods/setup', $this->body())->assertOk();
        $firstId = Period::where('day_of_week', 0)->where('number', 1)->value('id');

        $this->putJson('/api/periods/setup', $this->body(['days' => [0, 1, 2], 'periods_per_day' => 6, 'starts_at' => '08:00']))->assertOk();

        $this->assertSame(18, Period::count());
        $first = Period::find($firstId);
        $this->assertSame('08:00', substr($first->starts_at, 0, 5), 'the same period, new time');
    }

    public function test_periods_that_a_timetable_uses_are_not_removed(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/periods/setup', $this->body())->assertOk();
        $period = Period::where('day_of_week', 4)->where('number', 7)->first();
        $room = Classroom::factory()->create(['academic_year_id' => AcademicYear::factory()->create(['is_current' => true])->id]);
        $subject = Subject::factory()->create();
        $teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $subject->id])->create();
        DB::table('timetable_entries')->insert([
            'academic_year_id' => $room->academic_year_id, 'classroom_id' => $room->id, 'period_id' => $period->id, 'subject_id' => $subject->id,
            'teacher_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->putJson('/api/periods/setup', $this->body(['periods_per_day' => 6]))->assertUnprocessable()->assertJsonValidationErrors('periods_per_day');
        $this->putJson('/api/periods/setup', $this->body(['days' => [0, 1, 2, 3]]))->assertUnprocessable()->assertJsonValidationErrors('days');
        $this->assertSame(35, Period::count(), 'nothing changed');
        $this->putJson('/api/periods/setup', $this->body(['starts_at' => '08:00']))->assertOk();
    }

    public function test_the_setup_is_validated(): void
    {
        $this->actAsAdmin();
        $put = fn (array $o) => $this->putJson('/api/periods/setup', $this->body($o));

        $put(['days' => []])->assertUnprocessable()->assertJsonValidationErrors('days');
        $put(['days' => [0, 9]])->assertUnprocessable();
        $put(['days' => [1, 1]])->assertUnprocessable();
        $put(['periods_per_day' => 0])->assertUnprocessable()->assertJsonValidationErrors('periods_per_day');
        $put(['periods_per_day' => 20])->assertUnprocessable();
        $put(['starts_at' => '7am'])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $put(['period_minutes' => 5])->assertUnprocessable()->assertJsonValidationErrors('period_minutes');
        $put(['gap_minutes' => 90])->assertUnprocessable()->assertJsonValidationErrors('gap_minutes');
        $put(['long_break_after' => 7])->assertUnprocessable()->assertJsonValidationErrors('long_break_after');
        $put(['starts_at' => '20:00'])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->assertSame(0, Period::count());
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_are_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/periods')->assertForbidden();
        $this->putJson('/api/periods/setup', $this->body())->assertForbidden();
    }
}
