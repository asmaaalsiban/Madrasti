<?php

namespace Tests\Feature\Academic;

use App\Central\Actions\SeedTeachers;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Building;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Floor;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\Room;
use App\Modules\Academic\Models\Subject;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

class SeedTeachersTest extends TenantTestCase
{
    private function setUpSchool(): array
    {
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $grade = GradeLevel::factory()->create(['code' => '4', 'sort_order' => 7]);
        $math = Subject::factory()->create(['code' => 'MTH', 'name_en' => 'Math']);
        GradeLevelSubject::create(['grade_level_id' => $grade->id, 'subject_id' => $math->id, 'weekly_periods' => 5]);
        foreach (['Boys', 'Girls'] as $name) {
            $building = Building::create(['name' => $name, 'gender' => 'mixed']);
            $floor = Floor::create(['building_id' => $building->id, 'name' => 'Ground', 'level' => 0]);
            foreach (range(1, 3) as $n) {
                $room = Room::create(['floor_id' => $floor->id, 'name' => "$name-$n", 'kind' => 'classroom', 'type' => 'classroom']);
                Classroom::factory()->create(['grade_level_id' => $grade->id, 'academic_year_id' => $year->id, 'room_id' => $room->id]);
            }
        }

        return [$math];
    }

    public function test_it_creates_the_teachers_the_curriculum_needs_per_building_and_subject(): void
    {
        [$math] = $this->setUpSchool();

        // 3 sections x 5 periods = 15 per building; with a load of 10 each building needs 2 math teachers.
        $result = app(SeedTeachers::class)->handle('school9', 'Teacher12345', 10);

        $this->assertSame(4, $result['created']);
        $teachers = User::whereHas('staffProfile', fn ($q) => $q->where('subject_id', $math->id))->get();
        $this->assertCount(4, $teachers);
        foreach ($teachers as $teacher) {
            $this->assertSame(StaffType::Teacher, $teacher->staffProfile->staff_type);
            $this->assertFalse((bool) $teacher->must_change_password);
            $this->assertTrue(Hash::check('Teacher12345', $teacher->password));
            $this->assertStringEndsWith('@school9.test', $teacher->email);
        }
        $this->assertSame($teachers->count(), $teachers->pluck('email')->unique()->count());
        $this->assertSame($teachers->count(), $teachers->pluck('name')->unique()->count(), 'different names');
    }

    public function test_teachers_that_already_exist_count_and_a_second_run_creates_nothing(): void
    {
        [$math] = $this->setUpSchool();
        $existing = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id])->create();

        $first = app(SeedTeachers::class)->handle('school9', 'Teacher12345', 10);
        $second = app(SeedTeachers::class)->handle('school9', 'Teacher12345', 10);

        $this->assertSame(3, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(4, DB::table('staff_profiles')->where('subject_id', $math->id)->count());
        $this->assertTrue(User::whereKey($existing->id)->exists());
    }

    public function test_a_subject_nobody_teaches_gets_no_teacher(): void
    {
        $this->setUpSchool();
        Subject::factory()->create(['code' => 'ART']);

        $result = app(SeedTeachers::class)->handle('school9', 'Teacher12345', 24);

        $this->assertSame(2, $result['created'], 'one math teacher for each building');
        $this->assertSame(0, DB::table('staff_profiles')->join('subjects', 'subjects.id', '=', 'staff_profiles.subject_id')->where('subjects.code', 'ART')->count());
    }
}
