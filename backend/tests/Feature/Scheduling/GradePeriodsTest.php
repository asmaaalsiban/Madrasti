<?php

namespace Tests\Feature\Scheduling;

use App\Central\Actions\SeedStandardGrades;
use App\Central\Actions\SeedTeachers;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Building;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Floor;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\Room;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Services\AutoTeachingAssigner;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Modules\Scheduling\Services\PeriodSetup;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class GradePeriodsTest extends AcademicTestCase
{
    private AcademicYear $year;

    private GradeLevel $small;

    private GradeLevel $big;

    /** @var list<Classroom> */
    private array $smallRooms = [];

    /** @var list<Classroom> */
    private array $bigRooms = [];

    /** Grade 1 has 6 periods a day (30 lessons a week), grade 4 has 7 (35). */
    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true]);
        $this->small = GradeLevel::factory()->create(['code' => '1', 'name_en' => 'Grade 1', 'sort_order' => 4, 'periods_per_day' => 6]);
        $this->big = GradeLevel::factory()->create(['code' => '4', 'name_en' => 'Grade 4', 'sort_order' => 7]);
        $subjects = [];
        foreach (['ARB', 'ENG', 'MTH', 'SCI', 'REL', 'PE', 'ART'] as $code) {
            $subjects[$code] = Subject::factory()->create(['code' => $code, 'name_en' => $code]);
        }
        foreach (['ARB' => 6, 'ENG' => 5, 'MTH' => 5, 'SCI' => 5, 'REL' => 3, 'PE' => 3, 'ART' => 3] as $code => $n) { // 30
            GradeLevelSubject::create(['grade_level_id' => $this->small->id, 'subject_id' => $subjects[$code]->id, 'weekly_periods' => $n]);
        }
        foreach (['ARB' => 6, 'ENG' => 5, 'MTH' => 5, 'SCI' => 5, 'REL' => 3, 'PE' => 5, 'ART' => 6] as $code => $n) { // 35
            GradeLevelSubject::create(['grade_level_id' => $this->big->id, 'subject_id' => $subjects[$code]->id, 'weekly_periods' => $n]);
        }
        $floor = Floor::create(['building_id' => Building::create(['name' => 'Main', 'gender' => 'mixed'])->id, 'name' => 'Ground', 'level' => 0]);
        foreach ([[$this->small, 3, &$this->smallRooms], [$this->big, 3, &$this->bigRooms]] as [$grade, $count, &$list]) {
            foreach (range(1, $count) as $n) {
                $room = Room::create(['floor_id' => $floor->id, 'name' => "{$grade->code}-$n", 'kind' => 'classroom', 'type' => 'classroom']);
                $list[] = Classroom::factory()->create(['name' => "{$grade->code}-$n", 'grade_level_id' => $grade->id, 'academic_year_id' => $this->year->id, 'room_id' => $room->id]);
            }
        }
        app(PeriodSetup::class)->apply(['days' => [0, 1, 2, 3, 4], 'periods_per_day' => 7, 'starts_at' => '07:45', 'period_minutes' => 45, 'gap_minutes' => 5, 'long_break_after' => 3, 'long_break_minutes' => 20]);
        app(SeedTeachers::class)->handle('t', 'Teacher12345', 24);
        app(AutoTeachingAssigner::class)->handle(24, false);
    }

    public function test_the_school_day_lists_the_grades_with_their_own_periods_a_day(): void
    {
        $this->actAsAdmin();

        $grades = collect($this->getJson('/api/periods')->assertOk()->json('data.grades'))->keyBy('code');

        $this->assertSame(6, $grades['1']['periods_per_day']);
        $this->assertNull($grades['4']['periods_per_day'], 'empty = the whole school day');
    }

    public function test_an_admin_sets_the_periods_a_day_of_each_grade(): void
    {
        $this->actAsAdmin();

        $this->putJson('/api/periods/grades', ['grades' => [
            ['grade_level_id' => $this->small->id, 'periods_per_day' => 5],
            ['grade_level_id' => $this->big->id, 'periods_per_day' => 6],
        ]])->assertOk();
        $this->assertSame(5, $this->small->fresh()->periods_per_day);
        $this->assertSame(6, $this->big->fresh()->periods_per_day);

        $this->putJson('/api/periods/grades', ['grades' => [['grade_level_id' => $this->big->id, 'periods_per_day' => null]]])->assertOk();
        $this->assertNull($this->big->fresh()->periods_per_day);
    }

    public function test_the_periods_a_day_of_a_grade_are_validated(): void
    {
        $this->actAsAdmin();
        $put = fn (array $row) => $this->putJson('/api/periods/grades', ['grades' => [$row]]);

        $put(['grade_level_id' => $this->big->id, 'periods_per_day' => 8])->assertUnprocessable()->assertJsonValidationErrors('grades.0.periods_per_day');
        $put(['grade_level_id' => $this->big->id, 'periods_per_day' => 0])->assertUnprocessable();
        $put(['grade_level_id' => 999, 'periods_per_day' => 5])->assertUnprocessable()->assertJsonValidationErrors('grades.0.grade_level_id');
        $this->putJson('/api/periods/grades', [])->assertUnprocessable()->assertJsonValidationErrors('grades');
    }

    public function test_the_curriculum_compares_each_grade_with_its_own_week(): void
    {
        $this->actAsAdmin();

        $grades = collect($this->getJson('/api/curriculum')->assertOk()->json('data.grades'))->keyBy('code');

        $this->assertSame(30, $grades['1']['weekly_target'], '6 periods x 5 days');
        $this->assertSame(35, $grades['4']['weekly_target'], '7 periods x 5 days');
    }

    public function test_a_grade_with_fewer_periods_a_day_gets_no_lesson_after_its_last_period_and_every_cell_is_full(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/timetable/generate', ['seed' => 1])->assertOk()->assertJsonPath('data.total', 3 * 30 + 3 * 35)->assertJsonPath('data.placed', 3 * 30 + 3 * 35)
            ->assertJsonPath('data.unplaced', [])->assertJsonPath('data.incomplete', []);

        $number = Period::pluck('number', 'id');
        foreach ($this->smallRooms as $room) {
            $entries = TimetableEntry::where('classroom_id', $room->id)->get();
            $this->assertCount(30, $entries, $room->name);
            $this->assertLessThanOrEqual(6, $entries->map(fn ($e) => $number[$e->period_id])->max(), 'nothing in the 7th period');
        }
        foreach ($this->bigRooms as $room) {
            $this->assertSame(35, TimetableEntry::where('classroom_id', $room->id)->count(), $room->name);
        }
        $this->assertSame(0, DB::table('timetable_entries')->selectRaw('teacher_id, period_id, count(*) n')->groupBy('teacher_id', 'period_id')->havingRaw('count(*) > 1')->get()->count());
    }

    public function test_a_section_whose_curriculum_does_not_fill_its_week_is_reported(): void
    {
        GradeLevelSubject::where('grade_level_id', $this->small->id)->where('subject_id', Subject::where('code', 'ART')->value('id'))->update(['weekly_periods' => 1]);
        app(AutoTeachingAssigner::class)->handle(24, false);
        DB::table('classroom_subject_teacher')->whereIn('classroom_id', array_map(fn ($c) => $c->id, $this->smallRooms))
            ->where('subject_id', Subject::where('code', 'ART')->value('id'))->update(['weekly_periods' => 1]);
        $this->actAsAdmin();

        $report = $this->postJson('/api/timetable/generate', ['seed' => 1])->assertOk()->json('data');

        $this->assertCount(3, $report['incomplete'], 'the three sections of grade 1');
        $this->assertSame(['classroom' => '1-1', 'lessons' => 28, 'slots' => 30, 'empty' => 2], collect($report['incomplete'])->firstWhere('classroom', '1-1'));
        $this->assertSame(28, TimetableEntry::where('classroom_id', $this->smallRooms[0]->id)->count());
    }

    public function test_the_standard_plan_gives_grades_1_to_3_a_six_period_day_and_30_periods(): void
    {
        $this->actAsAdmin();
        $three = GradeLevel::factory()->create(['code' => '3', 'name_en' => 'Grade 3', 'sort_order' => 6]);

        $this->postJson('/api/curriculum/standard-plan')->assertOk();

        $this->assertSame(30, (int) DB::table('grade_level_subjects')->where('grade_level_id', $three->id)->sum('weekly_periods'));
        $this->assertSame(6, $three->fresh()->periods_per_day);
        $this->assertSame(6, $this->small->fresh()->periods_per_day, 'a value the school chose is kept');
        $this->assertSame(30, (int) DB::table('grade_level_subjects')->where('grade_level_id', $this->small->id)->sum('weekly_periods'));
        $this->assertNull($this->big->fresh()->periods_per_day);
        $this->assertSame(35, (int) DB::table('grade_level_subjects')->where('grade_level_id', $this->big->id)->sum('weekly_periods'));
    }

    public function test_the_standard_levels_of_the_kindergarten_have_a_six_period_day(): void
    {
        $existing = GradeLevel::create(['name_en' => 'KG1', 'name_ar' => 'روضة', 'sort_order' => 1, 'periods_per_day' => 5]);

        app(SeedStandardGrades::class)->handle();

        $this->assertSame(5, $existing->fresh()->periods_per_day, 'the school choice is kept');
        $this->assertSame(6, GradeLevel::where('code', 'BUD')->value('periods_per_day'));
        $this->assertSame(6, GradeLevel::where('code', 'KG2')->value('periods_per_day'));
        $this->assertNull(GradeLevel::where('code', '9')->value('periods_per_day'));
    }

    public function test_a_teacher_sees_their_own_week(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/timetable/generate', ['seed' => 1])->assertOk();
        $entry = TimetableEntry::first();
        $teacher = User::findOrFail($entry->teacher_id);

        Sanctum::actingAs($teacher);
        $data = $this->getJson('/api/timetable/me')->assertOk()->json('data');

        $this->assertSame(TimetableEntry::where('teacher_id', $teacher->id)->count(), count($data['entries']));
        $this->assertSame([$teacher->id], collect($data['entries'])->pluck('teacher.id')->unique()->values()->all());
        $this->assertCount(35, $data['periods']);
    }

    public function test_a_student_and_the_parent_see_the_week_of_the_section_without_the_periods_it_does_not_have(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/timetable/generate', ['seed' => 1])->assertOk();
        $account = User::factory()->role(Role::Student)->create();
        $student = Student::create(['user_id' => $account->id, 'student_number' => 'S-1', 'name_ar' => 'طالب', 'gender' => 'male']);
        Enrollment::create(['student_id' => $student->id, 'classroom_id' => $this->smallRooms[0]->id, 'academic_year_id' => $this->year->id]);
        $parent = User::factory()->role(Role::Guardian)->create();
        $student->guardians()->attach($parent->id, ['is_primary' => true]);

        Sanctum::actingAs($account);
        $own = $this->getJson('/api/timetable/me')->assertOk()->json('data');
        $this->assertCount(30, $own['periods'], 'no 7th period for grade 1');
        $this->assertCount(30, $own['entries']);
        $this->assertSame('1-1', $own['title']);

        Sanctum::actingAs($parent);
        $this->getJson("/api/students/{$student->id}/timetable")->assertOk()->assertJsonCount(30, 'data.entries');
        $other = Student::create(['student_number' => 'S-2', 'name_ar' => 'ليلى', 'gender' => 'female']);
        $this->getJson("/api/students/{$other->id}/timetable")->assertForbidden();
        $this->getJson('/api/timetable/me')->assertForbidden();

        $this->actAsAdmin();
        $this->getJson("/api/students/{$student->id}/timetable")->assertOk();
    }

    public function test_the_admin_sees_a_section_with_a_shorter_day_without_its_missing_periods(): void
    {
        $this->actAsAdmin();
        $this->postJson('/api/timetable/generate', ['seed' => 1])->assertOk();

        $this->getJson("/api/timetable?classroom_id={$this->smallRooms[0]->id}")->assertOk()->assertJsonCount(30, 'data.periods');
        $this->getJson("/api/timetable?classroom_id={$this->bigRooms[0]->id}")->assertOk()->assertJsonCount(35, 'data.periods');
    }
}
