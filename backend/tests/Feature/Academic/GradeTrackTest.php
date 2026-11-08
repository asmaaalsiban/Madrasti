<?php

namespace Tests\Feature\Academic;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\StudentRenewal;

/** Secondary tracks (health, business...): grades at the same level, told apart by their track. */
class GradeTrackTest extends AcademicTestCase
{
    public function test_a_grade_is_saved_with_its_track(): void
    {
        $this->actAsAdmin();
        $id = $this->postJson('/api/grade-levels', ['name_ar' => 'الحقل الصحي', 'name_en' => 'Health track', 'sort_order' => 11, 'track' => 'health'])->assertCreated()->assertJsonPath('data.track', 'health')->json('data.id');
        $this->putJson("/api/grade-levels/$id", ['name_ar' => 'الحقل الصحي', 'name_en' => 'Health track', 'sort_order' => 11, 'track' => null])->assertOk()->assertJsonPath('data.track', null);
    }

    public function test_a_student_in_a_track_goes_to_the_same_track_the_next_year(): void
    {
        $now = AcademicYear::factory()->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true]);
        $next = AcademicYear::factory()->create(['starts_on' => '2027-09-01', 'ends_on' => '2028-06-30', 'is_current' => false]);
        $first = GradeLevel::factory()->create(['sort_order' => 11, 'name_en' => 'First secondary']);
        $business11 = GradeLevel::factory()->create(['sort_order' => 12, 'track' => 'business', 'name_en' => 'Business 12']);
        $health11 = GradeLevel::factory()->create(['sort_order' => 12, 'track' => 'health', 'name_en' => 'Health 12']);
        $health10 = GradeLevel::factory()->create(['sort_order' => 11, 'track' => 'health', 'name_en' => 'Health 11']);
        $enrol = function (string $name, GradeLevel $g) use ($now): Student {
            static $n = 0;
            $room = Classroom::factory()->create(['name' => $name, 'grade_level_id' => $g->id, 'academic_year_id' => $now->id]);
            $s = Student::create(['student_number' => 'T-'.++$n, 'name_ar' => $name, 'name_en' => $name, 'gender' => 'male']);
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $room->id, 'academic_year_id' => $now->id]);

            return $s;
        };
        $healthStudent = $enrol('H', $health10);
        $plainStudent = $enrol('P', $first);
        $this->actAsAdmin();

        $this->postJson('/api/renewals/open', ['year_id' => $next->id])->assertCreated();
        $this->assertSame($health11->id, StudentRenewal::where('student_id', $healthStudent->id)->value('to_grade_level_id')); // stays in health, not the first track
        $this->assertContains(StudentRenewal::where('student_id', $plainStudent->id)->value('to_grade_level_id'), [$business11->id, $health11->id]); // no track: the first one (the admin can pick another)
    }
}
