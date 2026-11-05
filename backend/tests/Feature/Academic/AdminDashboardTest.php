<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Shared\Enums\Role;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class AdminDashboardTest extends AcademicTestCase
{
    public function test_empty_school_returns_zeros(): void
    {
        $this->actAsAdmin();
        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');

        $this->assertSame(0, $d['people']['students']);
        $this->assertSame(['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0], $d['students_today']);
        $this->assertSame(0.0, (float) $d['finance']['billed']);
        $this->assertCount(6, $d['finance']['monthly']);
        $this->assertSame([], $d['attendance_trend']);
        $this->assertSame(['procurement' => 0, 'privacy' => 0], $d['waiting']);
    }

    public function test_counts_today_trend_and_frequent_absences(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();
        $student = Student::create(['student_number' => 'D-1', 'name_ar' => 'أ', 'name_en' => 'A', 'gender' => 'male']);
        $other = Student::create(['student_number' => 'D-2', 'name_ar' => 'ب', 'name_en' => 'B', 'gender' => 'male']);
        foreach ([$student, $other] as $s) {
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $room->id, 'academic_year_id' => $room->academic_year_id]);
        }
        $classroom = $room->id;
        foreach ([0, 1, 2] as $ago) {
            StudentAttendance::create(['student_id' => $student->id, 'classroom_id' => $classroom, 'date' => now()->subDays($ago)->toDateString(), 'status' => 'absent', 'marked_by' => auth()->id()]);
        }
        StudentAttendance::create(['student_id' => $other->id, 'classroom_id' => $classroom, 'date' => now()->toDateString(), 'status' => 'present', 'marked_by' => auth()->id()]);

        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');

        $this->assertSame(2, $d['people']['students']);
        $this->assertSame(1, $d['students_today']['absent']);
        $this->assertSame(1, $d['students_today']['present']);
        $this->assertCount(3, $d['attendance_trend']);
        $this->assertSame($student->id, $d['frequent_absences'][0]['id']);
        $this->assertSame(3, $d['frequent_absences'][0]['days']);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_forbidden_for_non_admin(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());

        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }
}
