<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Building;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Floor;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\Room;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class AutoTeachingAssignmentTest extends AcademicTestCase
{
    private AcademicYear $year;

    private GradeLevel $grade;

    private Subject $math;

    private Subject $arabic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $this->grade = GradeLevel::factory()->create(['code' => '4', 'sort_order' => 7]);
        $this->math = Subject::factory()->create(['code' => 'MTH', 'name_en' => 'Math']);
        $this->arabic = Subject::factory()->create(['code' => 'ARB', 'name_en' => 'Arabic']);
        GradeLevelSubject::create(['grade_level_id' => $this->grade->id, 'subject_id' => $this->math->id, 'weekly_periods' => 5]);
        GradeLevelSubject::create(['grade_level_id' => $this->grade->id, 'subject_id' => $this->arabic->id, 'weekly_periods' => 6]);
    }

    private function building(string $name): Floor
    {
        $building = Building::create(['name' => $name, 'gender' => 'mixed']);

        return Floor::create(['building_id' => $building->id, 'name' => 'Ground', 'level' => 0]);
    }

    private function classroom(string $name, ?Floor $floor = null, ?GradeLevel $grade = null): Classroom
    {
        $room = $floor ? Room::create(['floor_id' => $floor->id, 'name' => "R-$name", 'kind' => 'classroom', 'type' => 'classroom']) : null;

        return Classroom::factory()->create(['name' => $name, 'grade_level_id' => ($grade ?? $this->grade)->id, 'academic_year_id' => $this->year->id, 'room_id' => $room?->id]);
    }

    private function teacher(Subject $subject, array $attributes = []): User
    {
        return User::factory()->staff(StaffType::Teacher, ['subject_id' => $subject->id])->create($attributes);
    }

    private function auto(array $extra = []): TestResponse
    {
        return $this->postJson('/api/teaching-assignments/auto', $extra);
    }

    public function test_teachers_are_filled_one_after_another_so_a_teacher_covers_several_sections(): void
    {
        $floor = $this->building('Main');
        foreach (['4-A', '4-B', '4-C'] as $name) {
            $this->classroom($name, $floor);
        }
        $first = $this->teacher($this->math, ['name' => 'Math 1']);
        $second = $this->teacher($this->math, ['name' => 'Math 2']);
        $this->teacher($this->arabic);
        $this->teacher($this->arabic);
        $this->actAsAdmin();

        $this->auto(['max_load' => 12])->assertOk()->assertJsonPath('data.assigned', 6);

        $math = TeachingAssignment::where('subject_id', $this->math->id)->get()->groupBy('teacher_id');
        $this->assertSame(2, $math[$first->id]->count(), 'two sections (10 periods) for the first teacher');
        $this->assertSame(1, $math[$second->id]->count());
        $this->assertSame(5, (int) TeachingAssignment::where('subject_id', $this->math->id)->first()->weekly_periods, 'the periods come from the curriculum');
    }

    public function test_a_teacher_stays_in_one_building(): void
    {
        $boys = $this->building('Boys');
        $girls = $this->building('Girls');
        $a = $this->classroom('4-A', $boys);
        $b = $this->classroom('4-B', $girls);
        $t1 = $this->teacher($this->math);
        $t2 = $this->teacher($this->math);
        $this->teacher($this->arabic);
        $this->teacher($this->arabic);
        $this->actAsAdmin();

        $this->auto()->assertOk()->assertJsonPath('data.assigned', 4);

        $teacherOf = fn (Classroom $c) => TeachingAssignment::where('classroom_id', $c->id)->where('subject_id', $this->math->id)->value('teacher_id');
        $this->assertNotSame($teacherOf($a), $teacherOf($b), 'nobody teaches in both buildings');
        $this->assertEqualsCanonicalizing([$t1->id, $t2->id], [$teacherOf($a), $teacherOf($b)]);
    }

    public function test_existing_assignments_are_kept_counted_and_a_second_run_adds_nothing(): void
    {
        $floor = $this->building('Main');
        $a = $this->classroom('4-A', $floor);
        $this->classroom('4-B', $floor);
        $busy = $this->teacher($this->math);
        $free = $this->teacher($this->math);
        $this->teacher($this->arabic);
        $this->teacher($this->arabic);
        TeachingAssignment::create(['classroom_id' => $a->id, 'subject_id' => $this->math->id, 'teacher_id' => $busy->id, 'weekly_periods' => 5]);
        $this->actAsAdmin();

        $this->auto(['max_load' => 8])->assertOk()->assertJsonPath('data.assigned', 3);

        $this->assertSame($busy->id, TeachingAssignment::where('classroom_id', $a->id)->where('subject_id', $this->math->id)->value('teacher_id'));
        $this->assertSame($free->id, TeachingAssignment::where('classroom_id', '!=', $a->id)->where('subject_id', $this->math->id)->value('teacher_id'), 'the busy teacher has no room for 5 more');
        $this->auto(['max_load' => 8])->assertOk()->assertJsonPath('data.assigned', 0);
    }

    public function test_a_preview_changes_nothing_and_reports_what_is_missing(): void
    {
        $floor = $this->building('Main');
        foreach (['4-A', '4-B', '4-C'] as $name) {
            $this->classroom($name, $floor);
        }
        $this->teacher($this->math);
        $this->actAsAdmin();

        $response = $this->auto(['dry_run' => true, 'max_load' => 10])->assertOk();

        $this->assertSame(0, TeachingAssignment::count());
        $response->assertJsonPath('data.dry_run', true)->assertJsonPath('data.assigned', 2);
        $unassigned = collect($response->json('data.unassigned'));
        $this->assertSame(4, $unassigned->count(), 'one math section and the three Arabic ones have no teacher');
        $this->assertSame(3, $unassigned->where('subject', 'Arabic')->count());

        $shortage = collect($response->json('data.shortages'))->firstWhere('subject', 'Arabic');
        $this->assertSame(18, $shortage['periods']);
        $this->assertSame(2, $shortage['teachers_needed']);
        $this->assertSame(0, $shortage['teachers_available']);
        $this->assertSame(2, $shortage['teachers_missing']);
        $this->assertSame(1, collect($response->json('data.shortages'))->firstWhere('subject', 'Math')['teachers_missing']);
    }

    public function test_only_active_teaching_staff_of_the_subject_are_used(): void
    {
        $floor = $this->building('Main');
        $this->classroom('4-A', $floor);
        $this->teacher($this->math)->update(['is_active' => false]);
        $other = $this->teacher($this->arabic);
        User::factory()->staff(StaffType::Nurse)->create();
        $this->actAsAdmin();

        $this->auto()->assertOk()->assertJsonPath('data.assigned', 1);
        $this->assertSame([$other->id], TeachingAssignment::pluck('teacher_id')->all());
    }

    public function test_only_classrooms_of_the_current_year_and_of_grades_with_a_curriculum_are_planned(): void
    {
        $floor = $this->building('Main');
        $this->classroom('4-A', $floor);
        $noCurriculum = GradeLevel::factory()->create(['code' => '5', 'sort_order' => 8]);
        $this->classroom('5-A', $floor, $noCurriculum);
        Classroom::factory()->create(['grade_level_id' => $this->grade->id, 'academic_year_id' => AcademicYear::factory()->create()->id]);
        $this->teacher($this->math);
        $this->teacher($this->arabic);
        $this->actAsAdmin();

        $this->auto()->assertOk()->assertJsonPath('data.assigned', 2);
        $this->assertSame(['4-A'], Classroom::whereIn('id', TeachingAssignment::pluck('classroom_id'))->pluck('name')->unique()->values()->all());
    }

    public function test_the_maximum_load_is_validated(): void
    {
        $this->actAsAdmin();

        $this->auto(['max_load' => 2])->assertUnprocessable()->assertJsonValidationErrors('max_load');
        $this->auto(['max_load' => 99])->assertUnprocessable()->assertJsonValidationErrors('max_load');
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_are_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->auto()->assertForbidden();
    }
}
