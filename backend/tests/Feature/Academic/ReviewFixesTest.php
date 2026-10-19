<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Modules\Audit\Models\AuditLog;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class ReviewFixesTest extends AcademicTestCase
{
    public function test_grade_level_accepts_empty_sort_order(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/grade-levels', ['name_ar' => 'ا', 'name_en' => 'A', 'sort_order' => null])
            ->assertCreated()->assertJsonPath('data.sort_order', 0);
    }

    public function test_subject_in_use_cannot_be_deleted(): void
    {
        $this->actAsAdmin();
        $subject = Subject::factory()->create();
        $room = Classroom::factory()->create();
        $teacher = User::factory()->role(Role::Teacher)->create();
        DB::table('classroom_subject_teacher')->insert(
            ['classroom_id' => $room->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id],
        );

        $this->deleteJson("/api/subjects/{$subject->id}")->assertUnprocessable();
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
    }

    public function test_year_with_terms_cannot_be_deleted(): void
    {
        $this->actAsAdmin();
        $year = AcademicYear::factory()->create();
        DB::table('terms')->insert(['academic_year_id' => $year->id, 'name' => 'T1', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);

        $this->deleteJson("/api/academic-years/{$year->id}")->assertUnprocessable();
        $this->assertDatabaseCount('terms', 1);
    }

    public function test_classroom_with_attendance_or_children_cannot_be_deleted(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();
        $subject = Subject::factory()->create();
        $teacher = User::factory()->role(Role::Teacher)->create();
        DB::table('classroom_subject_teacher')->insert(['classroom_id' => $room->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id]);

        $this->deleteJson("/api/classrooms/{$room->id}")->assertUnprocessable();
        $this->assertDatabaseHas('classrooms', ['id' => $room->id]);
    }

    public function test_homeroom_teacher_must_be_active_and_not_deleted(): void
    {
        $this->actAsAdmin();
        $gone = User::factory()->role(Role::Teacher)->create();
        $gone->delete();
        $inactive = User::factory()->role(Role::Teacher)->inactive()->create();
        $base = ['name' => 'A', 'grade_level_id' => GradeLevel::factory()->create()->id, 'academic_year_id' => AcademicYear::factory()->create()->id];

        $this->postJson('/api/classrooms', [...$base, 'homeroom_teacher_id' => $gone->id])->assertUnprocessable()->assertJsonValidationErrors('homeroom_teacher_id');
        $this->postJson('/api/classrooms', [...$base, 'homeroom_teacher_id' => $inactive->id])->assertUnprocessable()->assertJsonValidationErrors('homeroom_teacher_id');
    }

    public function test_classroom_year_cannot_change_while_students_are_enrolled(): void
    {
        $this->actAsAdmin();
        $room = Classroom::factory()->create();
        $other = AcademicYear::factory()->create();
        $studentId = DB::table('students')->insertGetId(['student_number' => 'S1', 'name_ar' => 'ط', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrollments')->insert(['student_id' => $studentId, 'classroom_id' => $room->id, 'academic_year_id' => $room->academic_year_id, 'created_at' => now(), 'updated_at' => now()]);

        $this->putJson("/api/classrooms/{$room->id}", ['name' => $room->name, 'grade_level_id' => $room->grade_level_id, 'academic_year_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('academic_year_id');
        $this->putJson("/api/classrooms/{$room->id}", ['name' => 'Renamed', 'grade_level_id' => $room->grade_level_id, 'academic_year_id' => $room->academic_year_id])->assertOk();
    }

    public function test_search_is_case_insensitive_and_escapes_wildcards(): void
    {
        $this->actAsAdmin();
        Subject::factory()->create(['code' => 'SCI', 'name_en' => 'Science', 'name_ar' => 'علوم']);
        Subject::factory()->create(['code' => 'MTH', 'name_en' => 'Math', 'name_ar' => 'رياضيات']);

        $this->getJson('/api/subjects?search=sCi')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/subjects?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/subjects?search=_')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_api_writes_are_audited_with_the_acting_admin(): void
    {
        $admin = $this->actAsAdmin();
        $a = $this->postJson('/api/academic-years', ['name' => 'A', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true])->json('data.id');
        $this->postJson('/api/academic-years', ['name' => 'B', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true]);

        $unset = AuditLog::where('auditable_type', AcademicYear::class)->where('auditable_id', $a)->where('action', 'updated')->first();
        $this->assertNotNull($unset);
        $this->assertSame($admin->id, $unset->user_id);
        $this->assertSame(2, AuditLog::where('auditable_type', AcademicYear::class)->where('action', 'created')->count());
    }

    #[DataProvider('guestRoutes')]
    public function test_guest_is_unauthorized_on_every_resource(string $uri): void
    {
        $this->getJson($uri)->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function guestRoutes(): array
    {
        return [
            'years' => ['/api/academic-years'],
            'levels' => ['/api/grade-levels'],
            'classrooms' => ['/api/classrooms'],
            'overview' => ['/api/admin/overview'],
        ];
    }
}
