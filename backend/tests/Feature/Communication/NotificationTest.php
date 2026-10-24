<?php

namespace Tests\Feature\Communication;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Models\Term;
use App\Modules\Grading\Models\Assessment;
use App\Modules\TeacherTools\Models\HomeworkAssignment;
use App\Modules\TeacherTools\Models\LessonPlan;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class NotificationTest extends AcademicTestCase
{
    private Classroom $a;

    private Classroom $b;

    private Subject $math;

    private User $teacher;

    private User $head;

    private User $studentA;

    private User $studentB;

    private User $parentA;

    private User $parentB;

    private Student $childA;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->math = Subject::factory()->create(['name_ar' => 'رياضيات', 'name_en' => 'Math']);
        $this->a = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        $this->b = Classroom::factory()->create(['name' => '5-B', 'academic_year_id' => $year->id]);
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $this->head = User::factory()->staff(StaffType::HeadOfDepartment, ['subject_id' => $this->math->id])->create();
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4]);

        foreach (['a' => $this->a, 'b' => $this->b] as $key => $room) {
            $user = User::factory()->role(Role::Student)->create();
            $student = Student::create(['student_number' => "S-$key", 'name_ar' => "طالب $key", 'name_en' => "Student $key", 'gender' => 'male', 'user_id' => $user->id]);
            Enrollment::create(['student_id' => $student->id, 'classroom_id' => $room->id, 'academic_year_id' => $year->id]);
            $parent = User::factory()->role(Role::Guardian)->create();
            $student->guardians()->attach($parent->id);
            $this->{'student'.strtoupper($key)} = $user;
            $this->{'parent'.strtoupper($key)} = $parent;
            if ($key === 'a') {
                $this->childA = $student;
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function bell(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => $n->data)->all();
    }

    public function test_new_homework_rings_the_bell_of_the_sections_students_and_parents_only(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/homework', ['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'title' => 'Page 10', 'due_on' => '2026-11-01'])->assertCreated();

        $student = $this->bell($this->studentA);
        $this->assertCount(1, $student);
        $this->assertSame('homework', $student[0]['kind']);
        $this->assertSame('/student/homework', $student[0]['link']);
        $this->assertSame('Page 10', $student[0]['params']['title']);
        $this->assertSame('Math', $student[0]['params']['subject_en']);

        $parent = $this->bell($this->parentA);
        $this->assertSame("/parent/children/{$this->childA->id}/homework", $parent[0]['link']);
        $this->assertSame('Student a', $parent[0]['params']['child_en']);

        $this->assertSame([], $this->bell($this->studentB));
        $this->assertSame([], $this->bell($this->parentB));
        $this->assertSame([], $this->bell($this->teacher));
    }

    public function test_a_daily_summary_notifies_once_and_a_correction_stays_quiet(): void
    {
        Sanctum::actingAs($this->teacher);
        $body = ['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'date' => '2026-10-20', 'summary' => 'Fractions'];
        $this->putJson('/api/daily-summaries', $body)->assertOk();
        $this->putJson('/api/daily-summaries', ['summary' => 'Fractions, corrected'] + $body)->assertOk();

        $this->assertCount(1, $this->bell($this->studentA));
        $this->assertSame('summary', $this->bell($this->studentA)[0]['kind']);
        $this->assertSame("/parent/children/{$this->childA->id}/summaries", $this->bell($this->parentA)[0]['link']);
    }

    public function test_publishing_grades_notifies_once(): void
    {
        $term = Term::create(['academic_year_id' => AcademicYear::first()->id, 'name' => 'T1', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']);
        $assessment = Assessment::create(['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'term_id' => $term->id, 'teacher_id' => $this->teacher->id, 'title' => 'Quiz 1', 'type' => 'quiz', 'max_score' => 20, 'weight' => 10]);
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/assessments/{$assessment->id}/publish")->assertOk();
        $this->postJson("/api/assessments/{$assessment->id}/publish")->assertOk(); // already published: no second ring

        $this->assertCount(1, $this->bell($this->studentA));
        $this->assertSame('grades', $this->bell($this->studentA)[0]['kind']);
        $this->assertSame("/parent/children/{$this->childA->id}/grades", $this->bell($this->parentA)[0]['link']);
    }

    public function test_the_lesson_plan_flow_rings_the_head_then_the_teacher_then_the_section(): void
    {
        $plan = LessonPlan::create(['teacher_id' => $this->teacher->id, 'classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'week_starts_on' => '2026-10-18', 'title' => 'Fractions', 'status' => 'draft', 'shared_with_parents' => true]);

        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/lesson-plans/{$plan->id}/submit")->assertOk();
        $this->assertSame('plan_submitted', $this->bell($this->head)[0]['kind']);
        $this->assertSame('/teacher/lesson-plans/review', $this->bell($this->head)[0]['link']);

        Sanctum::actingAs($this->head);
        $this->postJson("/api/lesson-plans/{$plan->id}/review", ['decision' => 'approve'])->assertOk();
        $this->assertSame('plan_reviewed', $this->bell($this->teacher)[0]['kind']);
        $this->assertSame('approve', $this->bell($this->teacher)[0]['params']['decision']);
        $this->assertSame('lesson_plan', $this->bell($this->studentA)[0]['kind']);
        $this->assertSame([], $this->bell($this->studentB));
    }

    public function test_a_rejected_plan_tells_the_teacher_why_and_nobody_else(): void
    {
        $plan = LessonPlan::create(['teacher_id' => $this->teacher->id, 'classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'week_starts_on' => '2026-10-18', 'title' => 'Fractions', 'status' => 'submitted', 'shared_with_parents' => true]);
        Sanctum::actingAs($this->head);
        $this->postJson("/api/lesson-plans/{$plan->id}/review", ['decision' => 'reject', 'note' => 'Add objectives'])->assertOk();

        $this->assertSame('Add objectives', $this->bell($this->teacher)[0]['params']['note']);
        $this->assertSame([], $this->bell($this->studentA));
    }

    public function test_a_student_answer_rings_the_homeworks_teacher_once(): void
    {
        $hw = HomeworkAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'title' => 'Page 10', 'due_at' => '2026-11-01 23:59:59', 'published_at' => now()]);
        Sanctum::actingAs($this->studentA);

        $this->postJson("/api/homework/{$hw->id}/submission", ['body' => 'first'])->assertOk();
        $this->postJson("/api/homework/{$hw->id}/submission", ['body' => 'changed'])->assertOk();

        $bell = $this->bell($this->teacher);
        $this->assertCount(1, $bell);
        $this->assertSame('homework_answer', $bell[0]['kind']);
        $this->assertSame('Student a', $bell[0]['params']['student_en']);
    }

    public function test_the_bell_lists_counts_and_marks_as_read_only_my_own(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/homework', ['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'title' => 'One', 'due_on' => '2026-11-01'])->assertCreated();
        $this->postJson('/api/homework', ['classroom_id' => $this->a->id, 'subject_id' => $this->math->id, 'title' => 'Two', 'due_on' => '2026-11-02'])->assertCreated();

        Sanctum::actingAs($this->studentA);
        $list = $this->getJson('/api/notifications')->assertOk();
        $list->assertJsonPath('data.unread', 2)->assertJsonCount(2, 'data.items');
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 2);

        $id = $list->json('data.items.0.id');
        $this->postJson("/api/notifications/$id/read")->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 1);

        Sanctum::actingAs($this->studentB);
        $this->postJson("/api/notifications/$id/read")->assertNotFound();
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 0);

        Sanctum::actingAs($this->studentA);
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 0);
        $this->assertSame(0, DB::table('notifications')->whereNull('read_at')->where('notifiable_id', $this->studentA->id)->count());
    }
}
