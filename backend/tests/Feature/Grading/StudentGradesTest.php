<?php

namespace Tests\Feature\Grading;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\Term;
use App\Modules\Grading\Models\Assessment;
use App\Modules\Grading\Models\AssessmentScore;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class StudentGradesTest extends AcademicTestCase
{
    private User $teacher;

    private Classroom $room;

    private Term $term1;

    private Term $term2;

    private Subject $math;

    private Subject $arabic;

    private Student $student;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->term1 = Term::create(['academic_year_id' => $year->id, 'name' => 'First term', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31']);
        $this->term2 = Term::create(['academic_year_id' => $year->id, 'name' => 'Second term', 'starts_on' => '2027-02-01', 'ends_on' => '2027-06-30']);
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

    private function assessment(Subject $subject, Term $term, string $title, int $max, int $weight, bool $published = true): Assessment
    {
        return Assessment::create([
            'classroom_id' => $this->room->id, 'subject_id' => $subject->id, 'term_id' => $term->id, 'teacher_id' => $this->teacher->id,
            'title' => $title, 'type' => 'quiz', 'max_score' => $max, 'weight' => $weight, 'is_published' => $published, 'held_on' => '2026-10-05',
        ]);
    }

    private function score(Assessment $assessment, ?float $score, ?string $status = null, ?float $first = null, ?string $remark = null): void
    {
        AssessmentScore::create(['assessment_id' => $assessment->id, 'student_id' => $this->student->id, 'score' => $score, 'status' => $status, 'first_score' => $first, 'remark' => $remark]);
    }

    private function grades(?Student $student = null): array
    {
        return $this->getJson('/api/students/'.($student ?? $this->student)->id.'/grades')->assertOk()->json('data');
    }

    private function subject(array $data, string $term, string $subject): ?array
    {
        $t = collect($data['terms'])->firstWhere('name', $term);

        return collect($t['subjects'])->first(fn ($s) => $s['subject']['name_en'] === $subject);
    }

    public function test_only_published_assessments_are_shown_with_the_result_of_each_subject(): void
    {
        $quiz = $this->assessment($this->math, $this->term1, 'Quiz', 20, 40);
        $exam = $this->assessment($this->math, $this->term1, 'Exam', 100, 60);
        $draft = $this->assessment($this->math, $this->term1, 'Draft', 10, 0, false);
        $this->score($quiz, 18);
        $this->score($exam, 60);
        $this->score($draft, 1);
        Sanctum::actingAs($this->parent);

        $math = $this->subject($this->grades(), 'First term', 'Math');

        $this->assertSame('pass', $math['result']);
        $this->assertEqualsWithDelta(72.0, $math['percent'], 0.01); // 90% x40 + 60% x60
        $this->assertCount(2, $math['assessments'], 'the draft is hidden');
        $this->assertEquals(18, collect($math['assessments'])->firstWhere('title', 'Quiz')['score']);
    }

    public function test_a_failing_subject_is_fail(): void
    {
        $exam = $this->assessment($this->math, $this->term1, 'Exam', 100, 100);
        $this->score($exam, 40);
        Sanctum::actingAs($this->parent);

        $this->assertSame('fail', $this->subject($this->grades(), 'First term', 'Math')['result']);
    }

    public function test_a_student_who_was_absent_waits_for_a_makeup_exam(): void
    {
        $this->score($this->assessment($this->math, $this->term1, 'Quiz', 20, 40), 18);
        $this->score($this->assessment($this->math, $this->term1, 'Exam', 100, 60), null, 'absent', null, 'Sick, private note');
        Sanctum::actingAs($this->parent);

        $math = $this->subject($this->grades(), 'First term', 'Math');

        $this->assertSame('makeup', $math['result']);
        $exam = collect($math['assessments'])->firstWhere('title', 'Exam');
        $this->assertSame('absent', $exam['status']);
        $this->assertNull($exam['score']);
        $this->assertArrayNotHasKey('remark', $exam, 'the teacher\'s private notes are not shown');
    }

    public function test_a_student_failing_the_exam_and_waiting_for_the_retake_is_marked_for_a_retake(): void
    {
        $exam = $this->assessment($this->math, $this->term1, 'Exam', 100, 100);
        $this->score($exam, null, 'retake', 38);
        Sanctum::actingAs($this->parent);

        $math = $this->subject($this->grades(), 'First term', 'Math');

        $this->assertSame('retake', $math['result']);
        $this->assertEquals(38, $math['assessments'][0]['first_score']);
    }

    public function test_after_the_retake_the_new_score_counts_and_the_first_attempt_is_still_shown(): void
    {
        $exam = $this->assessment($this->math, $this->term1, 'Exam', 100, 100);
        $this->score($exam, 52, 'retake', 38);
        Sanctum::actingAs($this->parent);

        $math = $this->subject($this->grades(), 'First term', 'Math');

        $this->assertSame('pass', $math['result']);
        $this->assertEquals(52, $math['assessments'][0]['score']);
        $this->assertEquals(38, $math['assessments'][0]['first_score']);
        $this->assertSame('retake', $math['assessments'][0]['status']);
    }

    public function test_a_subject_with_nothing_published_or_graded_has_no_result_and_terms_are_separate(): void
    {
        $this->assessment($this->arabic, $this->term1, 'Quiz', 20, 100); // nobody graded yet
        $this->score($this->assessment($this->math, $this->term2, 'Quiz', 20, 100), 15);
        Sanctum::actingAs($this->parent);

        $data = $this->grades();

        $this->assertSame('none', $this->subject($data, 'First term', 'Arabic')['result']);
        $this->assertNull($this->subject($data, 'First term', 'Arabic')['percent']);
        $this->assertSame('pass', $this->subject($data, 'Second term', 'Math')['result']);
        $this->assertNull($this->subject($data, 'First term', 'Math'), 'no published assessment of math in the first term');
        $this->assertEquals(50, $data['pass_percent']);
        $this->assertSame('5-A', $data['student']['classroom']);
    }

    public function test_the_student_and_the_parent_see_their_own_grades_but_nobody_else_does(): void
    {
        $other = Student::create(['student_number' => 'S-2', 'name_ar' => 'ليلى', 'gender' => 'female']);
        Enrollment::create(['student_id' => $other->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $this->room->academic_year_id]);

        Sanctum::actingAs($this->student->user);
        $this->getJson("/api/students/{$this->student->id}/grades")->assertOk();
        $this->getJson("/api/students/{$other->id}/grades")->assertForbidden();

        Sanctum::actingAs($this->parent);
        $this->getJson("/api/students/{$this->student->id}/grades")->assertOk();
        $this->getJson("/api/students/{$other->id}/grades")->assertForbidden();

        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->getJson("/api/students/{$this->student->id}/grades")->assertForbidden();

        $this->actAsAdmin();
        $this->getJson("/api/students/{$this->student->id}/grades")->assertOk();
    }
}
