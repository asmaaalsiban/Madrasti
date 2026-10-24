<?php

namespace Tests\Feature\TeacherTools;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Models\Term;
use App\Modules\TeacherTools\Models\BehaviorCriterion;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class BehaviorTest extends AcademicTestCase
{
    private User $teacher;

    private Classroom $a;

    private Classroom $b;

    private Student $student;

    private User $parent;

    /** @var list<int> */
    private array $c;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        Term::create(['academic_year_id' => $year->id, 'name' => 'First term', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']);
        $math = Subject::factory()->create();
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id])->create(['name' => 'Sara']);
        $this->a = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        $this->b = Classroom::factory()->create(['name' => '5-B', 'academic_year_id' => $year->id]);
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => $math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4]);
        $this->student = Student::create(['student_number' => 'S-1', 'name_ar' => 'طالب', 'name_en' => 'Student', 'gender' => 'male']);
        Enrollment::create(['student_id' => $this->student->id, 'classroom_id' => $this->a->id, 'academic_year_id' => $year->id]);
        $this->parent = User::factory()->role(Role::Guardian)->create();
        $this->student->guardians()->attach($this->parent->id);
        $this->c = BehaviorCriterion::orderBy('id')->pluck('id')->all();
    }

    private function evaluate(string $on, array $scores, array $extra = [])
    {
        return $this->putJson('/api/behavior/evaluations', ['student_id' => $this->student->id, 'evaluated_on' => $on, 'scores' => $scores, ...$extra]);
    }

    public function test_the_school_starts_with_ready_made_criteria(): void
    {
        $this->assertCount(7, $this->c);
        Sanctum::actingAs($this->teacher);
        $this->assertCount(7, $this->getJson('/api/behavior/criteria')->assertOk()->json('data'));
    }

    public function test_the_admin_adds_hides_and_removes_criteria_but_a_used_one_is_only_hidden(): void
    {
        $this->actAsAdmin();
        $new = $this->postJson('/api/behavior/criteria', ['name_ar' => 'الصلاة', 'name_en' => 'Prayer'])->assertCreated()->json('data.id');
        $this->putJson("/api/behavior/criteria/$new", ['name_ar' => 'الصلاة', 'name_en' => 'Prayer time', 'is_active' => false])->assertOk();
        $this->assertCount(7, BehaviorCriterion::where('is_active', true)->get());

        $this->deleteJson("/api/behavior/criteria/$new")->assertNoContent();
        $this->assertNull(BehaviorCriterion::find($new));

        Sanctum::actingAs($this->teacher);
        $this->evaluate('2026-10-04', [$this->c[0] => 4])->assertOk();
        $this->actAsAdmin();
        $this->deleteJson("/api/behavior/criteria/{$this->c[0]}")->assertNoContent();
        $this->assertFalse(BehaviorCriterion::find($this->c[0])->is_active);
    }

    public function test_a_teacher_cannot_change_criteria(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/behavior/criteria', ['name_ar' => 'x', 'name_en' => 'x'])->assertForbidden();
    }

    public function test_a_teacher_sees_only_their_sections_and_evaluates_a_student_for_a_week(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->assertSame(['5-A'], collect($this->getJson('/api/behavior/classrooms')->json('data'))->pluck('name')->all());

        $this->evaluate('2026-10-07', [$this->c[0] => 5, $this->c[1] => 3], ['note' => 'Good week'])->assertOk()
            ->assertJsonPath('data.week_starts_on', '2026-10-04')->assertJsonPath('data.evaluated_on', '2026-10-07');

        $week = $this->getJson("/api/behavior/week?classroom_id={$this->a->id}&date=2026-10-09")->assertOk();
        $week->assertJsonPath('data.week_starts_on', '2026-10-04')->assertJsonPath('data.students.0.evaluation.note', 'Good week');
        $this->getJson("/api/behavior/week?classroom_id={$this->b->id}")->assertForbidden();
    }

    public function test_evaluating_the_same_week_again_replaces_it(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->evaluate('2026-10-05', [$this->c[0] => 5, $this->c[1] => 3])->assertOk();
        $this->evaluate('2026-10-08', [$this->c[0] => 2])->assertOk();

        $this->assertSame(1, DB::table('behavior_evaluations')->count());
        $this->assertSame(1, DB::table('behavior_evaluation_scores')->count());
    }

    public function test_bad_evaluations_are_refused(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->evaluate('2026-10-05', [$this->c[0] => 6])->assertUnprocessable();
        $this->evaluate('2026-10-05', [9999 => 3])->assertUnprocessable()->assertJsonValidationErrors('scores');
        $this->evaluate('2026-10-05', [])->assertUnprocessable();
        $this->evaluate(now()->addWeek()->toDateString(), [$this->c[0] => 3])->assertUnprocessable();

        $other = Student::create(['student_number' => 'S-2', 'name_ar' => 'ب', 'name_en' => 'B', 'gender' => 'male']);
        Enrollment::create(['student_id' => $other->id, 'classroom_id' => $this->b->id, 'academic_year_id' => $this->a->academic_year_id]);
        $this->putJson('/api/behavior/evaluations', ['student_id' => $other->id, 'scores' => [$this->c[0] => 3]])->assertForbidden();
        $this->assertSame(0, DB::table('behavior_evaluations')->count());
    }

    public function test_weeks_build_the_month_and_months_build_the_term(): void
    {
        $second = User::factory()->staff(StaffType::Teacher)->create();
        TeachingAssignment::create(['classroom_id' => $this->a->id, 'subject_id' => Subject::factory()->create()->id, 'teacher_id' => $second->id, 'weekly_periods' => 2]);

        Sanctum::actingAs($this->teacher);
        // September: four weeks of 2, 4, 4, 2 on one criterion. October: one week of 5. Both are in the first term.
        foreach (['2026-09-06' => 2, '2026-09-13' => 4, '2026-09-20' => 4, '2026-09-27' => 2, '2026-10-18' => 5] as $day => $score) {
            $this->evaluate($day, [$this->c[0] => $score])->assertOk();
        }
        Sanctum::actingAs($second); // a second teacher in the first week: the week is the mean of both (2 and 4 = 3)
        $this->evaluate('2026-09-06', [$this->c[0] => 4])->assertOk();

        Sanctum::actingAs($this->parent);
        $data = $this->getJson("/api/students/{$this->student->id}/behavior")->assertOk()->json('data');

        $this->assertCount(5, $data['weeks']);
        $this->assertEquals(3.0, $data['weeks'][0]['criteria'][$this->c[0]]);
        $this->assertSame(2, $data['weeks'][0]['evaluations']);
        $this->assertSame(['2026-09', '2026-10'], array_column($data['months'], 'month'));
        $this->assertSame(4, $data['months'][0]['weeks']);
        $this->assertEquals(3.25, $data['months'][0]['criteria'][$this->c[0]]); // (3 + 4 + 4 + 2) / 4
        $this->assertEquals(5.0, $data['months'][1]['criteria'][$this->c[0]]);
        $this->assertCount(1, $data['terms']);
        $this->assertSame(2, $data['terms'][0]['months']);
        $this->assertEquals(4.13, $data['terms'][0]['criteria'][$this->c[0]]); // (3.25 + 5) / 2
        $this->assertEquals(4.13, $data['terms'][0]['overall']);
    }

    public function test_parents_see_only_the_remarks_and_notes_meant_for_them(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->evaluate('2026-10-05', [$this->c[0] => 4], ['note' => 'Shown', 'visible_to_parent' => true])->assertOk();
        $this->evaluate('2026-10-12', [$this->c[0] => 4], ['note' => 'Private', 'visible_to_parent' => false])->assertOk();
        $this->postJson('/api/behavior/notes', ['student_id' => $this->student->id, 'type' => 'positive', 'note' => 'Helped a friend', 'noted_on' => '2026-10-06'])->assertCreated();
        $this->postJson('/api/behavior/notes', ['student_id' => $this->student->id, 'type' => 'negative', 'note' => 'Teacher only', 'visible_to_parent' => false])->assertCreated();

        $staff = $this->getJson("/api/students/{$this->student->id}/behavior")->json('data');
        $this->assertCount(2, $staff['remarks']);
        $this->assertCount(2, $staff['notes']);

        Sanctum::actingAs($this->parent);
        $parent = $this->getJson("/api/students/{$this->student->id}/behavior")->json('data');
        $this->assertSame(['Shown'], array_column($parent['remarks'], 'note'));
        $this->assertSame(['Helped a friend'], array_column($parent['notes'], 'note'));
    }

    public function test_only_the_family_the_students_teachers_and_admins_read_the_results(): void
    {
        foreach ([User::factory()->role(Role::Guardian)->create(), User::factory()->staff(StaffType::Teacher)->create(), User::factory()->role(Role::Student)->create()] as $user) {
            Sanctum::actingAs($user);
            $this->getJson("/api/students/{$this->student->id}/behavior")->assertForbidden();
        }
        Sanctum::actingAs($this->teacher);
        $this->getJson("/api/students/{$this->student->id}/behavior")->assertOk();
        $this->actAsAdmin();
        $this->getJson("/api/students/{$this->student->id}/behavior")->assertOk();
    }

    public function test_a_note_defaults_to_today_and_is_deleted_by_its_author_or_an_admin_only(): void
    {
        Sanctum::actingAs($this->teacher);
        $id = $this->postJson('/api/behavior/notes', ['student_id' => $this->student->id, 'type' => 'negative', 'note' => 'x'])->assertCreated()->assertJsonPath('data.noted_on', now()->toDateString())->json('data.id');

        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->deleteJson("/api/behavior/notes/$id")->assertForbidden();
        Sanctum::actingAs($this->parent);
        $this->postJson('/api/behavior/notes', ['student_id' => $this->student->id, 'type' => 'positive', 'note' => 'x'])->assertForbidden();
        $this->actAsAdmin();
        $this->deleteJson("/api/behavior/notes/$id")->assertNoContent();
    }
}
