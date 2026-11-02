<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\Term;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Grading\Models\Assessment;
use App\Modules\Grading\Models\AssessmentScore;
use App\Modules\TeacherTools\Models\BehaviorCriterion;
use App\Modules\TeacherTools\Models\BehaviorEvaluation;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class ReportCardTest extends AcademicTestCase
{
    private User $teacher;

    private Classroom $room;

    private Term $t1;

    private Term $t2;

    private Subject $math;

    private Subject $arabic;

    private Student $student;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true, 'name' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->t1 = Term::create(['academic_year_id' => $year->id, 'name' => 'First term', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31']);
        $this->t2 = Term::create(['academic_year_id' => $year->id, 'name' => 'Second term', 'starts_on' => '2027-02-01', 'ends_on' => '2027-06-30']);
        $this->math = Subject::factory()->create(['name_en' => 'Math']);
        $this->arabic = Subject::factory()->create(['name_en' => 'Arabic']);
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $this->room = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        $account = User::factory()->role(Role::Student)->create();
        $this->student = Student::create(['user_id' => $account->id, 'student_number' => 'S-1', 'name_ar' => 'أحمد', 'name_en' => 'Ahmad', 'gender' => 'male']);
        Enrollment::create(['student_id' => $this->student->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $year->id]);
        $this->parent = User::factory()->role(Role::Guardian)->create();
        $this->student->guardians()->attach($this->parent->id, ['is_primary' => true]);
    }

    private function graded(Subject $subject, Term $term, float $score, int $max = 100, bool $published = true): void
    {
        $a = Assessment::create(['classroom_id' => $this->room->id, 'subject_id' => $subject->id, 'term_id' => $term->id, 'teacher_id' => $this->teacher->id, 'title' => 'Exam', 'type' => 'exam', 'max_score' => $max, 'weight' => 100, 'is_published' => $published, 'held_on' => '2026-10-05']);
        AssessmentScore::create(['assessment_id' => $a->id, 'student_id' => $this->student->id, 'score' => $score, 'status' => 'graded']);
    }

    private function card(?Student $student = null)
    {
        return $this->getJson('/api/students/'.($student ?? $this->student)->id.'/report-card');
    }

    public function test_the_card_has_every_subject_by_term_with_annual_results_and_averages(): void
    {
        $this->graded($this->math, $this->t1, 90);
        $this->graded($this->math, $this->t2, 70);
        $this->graded($this->arabic, $this->t1, 40); // fails
        $this->graded($this->arabic, $this->t2, 30);
        Sanctum::actingAs($this->parent);

        $data = $this->card()->assertOk()->json('data');

        $this->assertSame('2026/2027', $data['year']['name']);
        $this->assertSame('5-A', $data['student']['classroom']);
        $this->assertSame(['Arabic', 'Math'], collect($data['subjects'])->pluck('subject.name_en')->all());
        $math = collect($data['subjects'])->firstWhere('subject.name_en', 'Math');
        $this->assertEquals(90, $math['terms'][$this->t1->id]['percent']);
        $this->assertEquals(80, $math['annual']); // the mean of the two terms
        $this->assertSame('pass', $math['annual_result']);
        $this->assertSame('fail', collect($data['subjects'])->firstWhere('subject.name_en', 'Arabic')['annual_result']);
        $this->assertEquals([65.0, 50.0], array_column($data['terms'], 'average')); // (90+40)/2 and (70+30)/2
        $this->assertEquals(57.5, $data['annual_average']);
    }

    public function test_only_published_grades_count_and_a_subject_waiting_for_a_makeup_is_pending(): void
    {
        $this->graded($this->math, $this->t1, 90);
        $this->graded($this->arabic, $this->t1, 10, 100, false); // not published: invisible
        $a = Assessment::create(['classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'term_id' => $this->t2->id, 'teacher_id' => $this->teacher->id, 'title' => 'Exam', 'type' => 'exam', 'max_score' => 100, 'weight' => 100, 'is_published' => true, 'held_on' => '2027-03-01']);
        AssessmentScore::create(['assessment_id' => $a->id, 'student_id' => $this->student->id, 'score' => null, 'status' => 'absent']);
        Sanctum::actingAs($this->parent);

        $data = $this->card()->json('data');

        $this->assertSame(['Math'], collect($data['subjects'])->pluck('subject.name_en')->all());
        $this->assertSame('pending', $data['subjects'][0]['annual_result']);
        $this->assertEquals(90, $data['subjects'][0]['annual']);
    }

    public function test_attendance_is_counted_by_term_and_for_the_year(): void
    {
        $this->graded($this->math, $this->t1, 90);
        foreach ([['2026-10-04', 'absent'], ['2026-10-05', 'absent'], ['2026-10-06', 'late'], ['2027-03-01', 'absent'], ['2027-03-02', 'present']] as [$date, $status]) {
            StudentAttendance::create(['student_id' => $this->student->id, 'classroom_id' => $this->room->id, 'date' => $date, 'status' => $status, 'marked_by' => $this->teacher->id]);
        }
        Sanctum::actingAs($this->parent);

        $data = $this->card()->json('data');

        $this->assertSame([2, 1], [$data['terms'][0]['absent'], $data['terms'][0]['late']]);
        $this->assertSame(1, $data['terms'][1]['absent']);
        $this->assertSame(['absent' => 3, 'late' => 1, 'excused' => 0, 'days' => 5], $data['attendance']);
    }

    public function test_behaviour_is_the_average_of_the_scores_and_a_hidden_evaluation_is_only_for_the_admin(): void
    {
        $this->graded($this->math, $this->t1, 90);
        $criterion = BehaviorCriterion::first();
        $shown = BehaviorEvaluation::create(['student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'week_starts_on' => '2026-10-04', 'evaluated_on' => '2026-10-05', 'visible_to_parent' => true]);
        $shown->scores()->create(['behavior_criterion_id' => $criterion->id, 'score' => 5]);
        $hidden = BehaviorEvaluation::create(['student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'week_starts_on' => '2026-10-11', 'evaluated_on' => '2026-10-12', 'visible_to_parent' => false]);
        $hidden->scores()->create(['behavior_criterion_id' => $criterion->id, 'score' => 1]);

        Sanctum::actingAs($this->parent);
        $this->assertEquals(5, $this->card()->json('data.behavior'));
        $this->assertEquals(5, $this->card()->json('data.terms.0.behavior'));

        $this->actAsAdmin();
        $this->assertEquals(3, $this->card()->json('data.behavior')); // the admin sees both
    }

    public function test_the_admin_the_student_and_the_parent_can_read_it_and_nobody_else(): void
    {
        $this->graded($this->math, $this->t1, 90);

        $this->actAsAdmin();
        $this->card()->assertOk();
        Sanctum::actingAs($this->student->user);
        $this->card()->assertOk();
        Sanctum::actingAs($this->parent);
        $this->card()->assertOk();

        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create()); // another child's parent
        $this->card()->assertForbidden();
        Sanctum::actingAs($this->teacher);
        $this->card()->assertForbidden();
        $other = Student::create(['student_number' => 'S-2', 'name_ar' => 'سامي', 'gender' => 'male', 'user_id' => User::factory()->role(Role::Student)->create()->id]);
        Sanctum::actingAs($this->student->user);
        $this->card($other)->assertForbidden();
    }

    public function test_a_student_without_a_classroom_or_grades_gets_an_empty_card_not_an_error(): void
    {
        $lone = Student::create(['student_number' => 'S-9', 'name_ar' => 'وحيد', 'gender' => 'male']);
        $this->actAsAdmin();

        $data = $this->card($lone)->assertOk()->json('data');

        $this->assertSame([], $data['subjects']);
        $this->assertNull($data['annual_average']);
        $this->assertNull($data['student']['classroom']);
    }

    public function test_a_school_with_no_current_year_gets_an_empty_card(): void
    {
        AcademicYear::query()->update(['is_current' => false]);
        $this->actAsAdmin();

        $data = $this->card()->assertOk()->json('data');
        $this->assertNull($data['year']);
        $this->assertSame([], $data['terms']);
    }
}
