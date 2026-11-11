<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

/**
 * Every endpoint that returns one student's data must refuse everyone who is not that student, their parent
 * (or, where the rules say so, the school's staff). A new /students/{student}/... route should be added here.
 */
class StudentDataAccessTest extends AcademicTestCase
{
    private Student $mine;

    private Student $other;

    private User $parent;

    private User $otherParent;

    private User $myStudentAccount;

    private User $otherStudentAccount;

    private User $teacher;

    /** @return list<string> */
    private function paths(Student $s): array
    {
        $id = $s->id;

        return [
            "/api/attendance/students/{$id}",
            "/api/students/{$id}/exams",
            "/api/students/{$id}/timetable",
            "/api/students/{$id}/daily-summaries",
            "/api/students/{$id}/fees",
            "/api/students/{$id}/lesson-plans",
            "/api/students/{$id}/behavior",
            "/api/students/{$id}/report-card",
            "/api/students/{$id}/motivation",
            "/api/students/{$id}/homework",
            "/api/students/{$id}/grades",
            "/api/students/{$id}/transport",
            "/api/students/{$id}/quizzes",
            "/api/students/{$id}/warnings",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $room = Classroom::factory()->create(['academic_year_id' => $year->id]);

        $this->myStudentAccount = User::factory()->role(Role::Student)->create();
        $this->otherStudentAccount = User::factory()->role(Role::Student)->create();
        $this->mine = Student::create(['user_id' => $this->myStudentAccount->id, 'student_number' => 'S-1', 'name_ar' => 'أحمد', 'name_en' => 'Ahmad', 'gender' => 'male']);
        $this->other = Student::create(['user_id' => $this->otherStudentAccount->id, 'student_number' => 'S-2', 'name_ar' => 'سارة', 'name_en' => 'Sara', 'gender' => 'female']);
        foreach ([$this->mine, $this->other] as $s) {
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $room->id, 'academic_year_id' => $year->id]);
        }

        $this->parent = User::factory()->role(Role::Guardian)->create();
        $this->otherParent = User::factory()->role(Role::Guardian)->create();
        $this->mine->guardians()->attach($this->parent->id, ['is_primary' => true]);
        $this->other->guardians()->attach($this->otherParent->id, ['is_primary' => true]);

        $this->teacher = User::factory()->staff(StaffType::Teacher)->create();
    }

    public function test_a_parent_cannot_read_another_familys_child_on_any_endpoint(): void
    {
        Sanctum::actingAs($this->parent);

        foreach ($this->paths($this->other) as $path) {
            $this->getJson($path)->assertForbidden("{$path} leaked another child to a parent");
        }
    }

    public function test_a_student_cannot_read_a_classmate_on_any_endpoint(): void
    {
        Sanctum::actingAs($this->myStudentAccount);

        foreach ($this->paths($this->other) as $path) {
            $this->getJson($path)->assertForbidden("{$path} leaked a classmate to a student");
        }
    }

    public function test_a_teacher_with_no_link_to_the_student_cannot_read_private_endpoints(): void
    {
        Sanctum::actingAs($this->teacher);

        // Fees, grades, transport and the like are not for a teacher who has no role in the school's money or this class.
        foreach (['fees', 'grades', 'transport', 'timetable', 'homework'] as $part) {
            $this->getJson("/api/students/{$this->mine->id}/{$part}")->assertForbidden("{$part} leaked to an unrelated teacher");
        }
    }

    public function test_a_parent_is_not_refused_for_their_own_child(): void
    {
        Sanctum::actingAs($this->parent);

        foreach ($this->paths($this->mine) as $path) {
            $this->assertNotContains($this->getJson($path)->status(), [401, 403], "{$path} refused a parent their own child");
        }
    }

    public function test_a_student_is_not_refused_for_themselves_where_the_rules_allow_it(): void
    {
        Sanctum::actingAs($this->myStudentAccount);

        // Fees are for parents only, so they are left out here.
        foreach ($this->paths($this->mine) as $path) {
            if (str_ends_with($path, '/fees')) {
                continue;
            }
            $this->assertNotContains($this->getJson($path)->status(), [401, 403], "{$path} refused a student their own data");
        }
    }

    public function test_nobody_is_let_in_without_signing_in(): void
    {
        foreach ($this->paths($this->mine) as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }
}
