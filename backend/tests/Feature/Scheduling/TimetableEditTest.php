<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Building;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Floor;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Room;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Modules\Scheduling\Services\PeriodSetup;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Academic\AcademicTestCase;

class TimetableEditTest extends AcademicTestCase
{
    private AcademicYear $year;

    private Classroom $a;

    private Classroom $b;

    private Subject $math;

    private Subject $art;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $grade = GradeLevel::factory()->create(['sort_order' => 7, 'periods_per_day' => 5]);
        $boys = Floor::create(['building_id' => Building::create(['name' => 'Boys', 'gender' => 'mixed'])->id, 'name' => 'G', 'level' => 0]);
        $girls = Floor::create(['building_id' => Building::create(['name' => 'Girls', 'gender' => 'mixed'])->id, 'name' => 'G', 'level' => 0]);
        $make = fn (Floor $floor, string $name) => Classroom::factory()->create([
            'name' => $name, 'grade_level_id' => $grade->id, 'academic_year_id' => $this->year->id,
            'room_id' => Room::create(['floor_id' => $floor->id, 'name' => "R$name", 'kind' => 'classroom', 'type' => 'classroom'])->id,
        ]);
        $this->a = $make($boys, '4-A');
        $this->b = $make($girls, '4-B');
        $this->math = Subject::factory()->create(['code' => 'MTH', 'name_en' => 'Math']);
        $this->art = Subject::factory()->create(['code' => 'ART', 'name_en' => 'Art']);
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create(['name' => 'Sara']);
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 5]);
        TeachingAssignment::create(['classroom_id' => $this->b->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 5]);
        app(PeriodSetup::class)->apply(['days' => [0, 1], 'periods_per_day' => 7, 'starts_at' => '08:00', 'period_minutes' => 45, 'gap_minutes' => 5]);
    }

    private function period(int $day, int $number): Period
    {
        return Period::where('day_of_week', $day)->where('number', $number)->firstOrFail();
    }

    private function cell(Classroom $room, Period $period, array $extra = []): array
    {
        return ['classroom_id' => $room->id, 'period_id' => $period->id, 'subject_id' => $this->math->id, ...$extra];
    }

    public function test_a_lesson_is_placed_by_hand_with_the_assigned_teacher_and_the_room_and_is_locked(): void
    {
        $this->actAsAdmin();

        $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(0, 1)))->assertOk()
            ->assertJsonPath('data.teacher.id', $this->teacher->id)->assertJsonPath('data.room.id', $this->a->room_id)->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.subject.id', $this->math->id);

        $this->assertSame(1, TimetableEntry::count());
        $this->assertSame($this->year->id, TimetableEntry::first()->academic_year_id);
    }

    public function test_placing_a_lesson_again_in_the_same_cell_replaces_it(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(0, 1)))->assertOk();
        $other = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->art->id])->create();
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $this->art->id, 'teacher_id' => $other->id, 'weekly_periods' => 1]);

        $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(0, 1), ['subject_id' => $this->art->id]))->assertOk()->assertJsonPath('data.teacher.id', $other->id);

        $this->assertSame(1, TimetableEntry::count());
    }

    public function test_a_teacher_cannot_be_in_two_sections_at_the_same_time(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(0, 2)))->assertOk();

        $response = $this->putJson('/api/timetable/entries', $this->cell($this->b, $this->period(0, 2)))->assertUnprocessable()->assertJsonValidationErrors('period_id');

        $this->assertStringContainsString('4-A', $response->json('errors.period_id.0'), 'the message names the section where the teacher already is');
        $this->assertSame(1, TimetableEntry::count());
    }

    public function test_a_teacher_cannot_change_building_between_two_consecutive_periods(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(0, 2)))->assertOk();

        $this->putJson('/api/timetable/entries', $this->cell($this->b, $this->period(0, 3)))->assertUnprocessable()->assertJsonValidationErrors('period_id');
        $this->putJson('/api/timetable/entries', $this->cell($this->b, $this->period(0, 4)))->assertOk(); // one period in between is fine
    }

    public function test_an_unavailable_period_is_refused(): void
    {
        $this->actAsAdmin();
        DB::table('teacher_unavailabilities')->insert(['teacher_id' => $this->teacher->id, 'period_id' => $this->period(1, 1)->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(1, 1)))->assertUnprocessable()->assertJsonValidationErrors('period_id');
    }

    public function test_a_period_the_section_does_not_have_and_a_subject_without_a_teacher_are_refused(): void
    {
        $this->actAsAdmin();

        $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(0, 6)))->assertUnprocessable()->assertJsonValidationErrors('period_id');
        $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(0, 1), ['subject_id' => $this->art->id]))->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(0, 1), ['classroom_id' => 'abc']))->assertUnprocessable();
        $this->assertSame(0, TimetableEntry::count());
    }

    public function test_a_lesson_is_removed_locked_and_unlocked(): void
    {
        $this->actAsAdmin();
        $id = $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(0, 1)))->json('data.id');

        $this->putJson("/api/timetable/entries/$id/lock", ['locked' => false])->assertOk()->assertJsonPath('data.is_locked', false);
        $this->assertFalse(TimetableEntry::find($id)->is_locked);
        $this->putJson("/api/timetable/entries/$id/lock", ['locked' => true])->assertOk()->assertJsonPath('data.is_locked', true);
        $this->putJson("/api/timetable/entries/$id/lock", [])->assertUnprocessable();

        $this->deleteJson("/api/timetable/entries/$id")->assertNoContent();
        $this->assertSame(0, TimetableEntry::count());
    }

    public function test_a_teachers_unavailable_periods_are_listed_and_replaced(): void
    {
        $this->actAsAdmin();
        $one = $this->period(0, 1);
        $two = $this->period(0, 2);

        $this->putJson("/api/teachers/{$this->teacher->id}/unavailability", ['period_ids' => [$one->id, $two->id]])->assertOk()->assertJsonPath('data.period_ids', [$one->id, $two->id]);
        $this->putJson("/api/teachers/{$this->teacher->id}/unavailability", ['period_ids' => [$two->id]])->assertOk();
        $this->assertSame([$two->id], $this->getJson("/api/teachers/{$this->teacher->id}/unavailability")->json('data.period_ids'));
        $this->putJson("/api/teachers/{$this->teacher->id}/unavailability", ['period_ids' => []])->assertOk();
        $this->assertSame(0, DB::table('teacher_unavailabilities')->count());
    }

    public function test_making_a_teacher_unavailable_reports_the_lessons_that_now_clash(): void
    {
        $this->actAsAdmin();
        $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(0, 1)))->assertOk();

        $response = $this->putJson("/api/teachers/{$this->teacher->id}/unavailability", ['period_ids' => [$this->period(0, 1)->id]])->assertOk();

        $this->assertSame(['4-A'], collect($response->json('data.clashes'))->pluck('classroom')->all());
        $this->putJson("/api/teachers/{$this->teacher->id}/unavailability", ['period_ids' => [999]])->assertUnprocessable();
        $this->putJson('/api/teachers/'.User::factory()->role(Role::Guardian)->create()->id.'/unavailability', ['period_ids' => []])->assertNotFound();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_are_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->putJson('/api/timetable/entries', $this->cell($this->a, $this->period(0, 1)))->assertForbidden();
        $this->getJson("/api/teachers/{$this->teacher->id}/unavailability")->assertForbidden();
    }
}
