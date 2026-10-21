<?php

namespace Tests\Feature\Grading;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Models\Term;
use App\Modules\Grading\Models\Assessment;
use App\Modules\Grading\Models\AssessmentScore;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class AssessmentTest extends AcademicTestCase
{
    private User $teacher;

    private Classroom $room;

    private Subject $math;

    private Term $term;

    /** @var list<Student> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->term = Term::create(['academic_year_id' => $year->id, 'name' => 'First term', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31']);
        $this->math = Subject::factory()->create(['name_en' => 'Math']);
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $this->room = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        TeachingAssignment::create(['classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4]);
        foreach ([1, 2, 3] as $n) {
            $student = Student::create(['student_number' => "S-$n", 'name_ar' => "طالب $n", 'name_en' => "Student $n", 'gender' => 'male']);
            Enrollment::create(['student_id' => $student->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $year->id]);
            $this->students[] = $student;
        }
    }

    private function body(array $override = []): array
    {
        return [...[
            'classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'term_id' => $this->term->id,
            'title' => 'Quiz 1', 'type' => 'quiz', 'max_score' => 20, 'weight' => 10, 'held_on' => '2026-10-05',
        ], ...$override];
    }

    private function assessment(array $override = []): Assessment
    {
        return Assessment::create([...$this->body(), 'teacher_id' => $this->teacher->id, ...$override]);
    }

    public function test_the_teacher_sees_only_their_own_subjects_and_the_terms_of_the_current_year(): void
    {
        $other = Classroom::factory()->create(['academic_year_id' => $this->room->academic_year_id]);
        TeachingAssignment::create(['classroom_id' => $other->id, 'subject_id' => Subject::factory()->create()->id, 'teacher_id' => User::factory()->staff()->create()->id, 'weekly_periods' => 2]);
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/grading/options')->assertOk()
            ->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.classroom.name', '5-A')
            ->assertJsonPath('data.assignments.0.subject.name_en', 'Math')
            ->assertJsonCount(1, 'data.terms')
            ->assertJsonPath('data.terms.0.name', 'First term');
    }

    public function test_the_teacher_creates_an_assessment_for_a_classroom_and_subject_they_teach(): void
    {
        Sanctum::actingAs($this->teacher);

        $id = $this->postJson('/api/assessments', $this->body())->assertCreated()
            ->assertJsonPath('data.title', 'Quiz 1')
            ->assertJsonPath('data.teacher.id', $this->teacher->id)
            ->assertJsonPath('data.is_published', false)
            ->json('data.id');

        $this->assertDatabaseHas('assessments', ['id' => $id, 'teacher_id' => $this->teacher->id, 'max_score' => 20]);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => Assessment::class, 'action' => 'created']);
    }

    public function test_a_teacher_cannot_create_an_assessment_for_a_subject_or_classroom_they_do_not_teach(): void
    {
        $science = Subject::factory()->create();
        $otherRoom = Classroom::factory()->create(['academic_year_id' => $this->room->academic_year_id]);
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/assessments', $this->body(['subject_id' => $science->id]))->assertForbidden();
        $this->postJson('/api/assessments', $this->body(['classroom_id' => $otherRoom->id]))->assertForbidden();
        $this->assertSame(0, Assessment::count());
    }

    public function test_validation_of_an_assessment(): void
    {
        $oldYear = AcademicYear::factory()->create();
        $oldTerm = Term::create(['academic_year_id' => $oldYear->id, 'name' => 'Old', 'starts_on' => '2025-09-01', 'ends_on' => '2026-01-31']);
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/assessments', $this->body(['type' => 'party']))->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/assessments', $this->body(['max_score' => 0]))->assertUnprocessable()->assertJsonValidationErrors('max_score');
        $this->postJson('/api/assessments', $this->body(['weight' => 101]))->assertUnprocessable()->assertJsonValidationErrors('weight');
        $this->postJson('/api/assessments', $this->body(['title' => '']))->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/assessments', $this->body(['term_id' => $oldTerm->id]))->assertUnprocessable()->assertJsonValidationErrors('term_id');
        $this->postJson('/api/assessments', $this->body(['classroom_id' => 'abc']))->assertForbidden(); // no such assignment
    }

    public function test_the_weights_of_a_subject_in_a_term_cannot_add_up_to_more_than_100(): void
    {
        $this->assessment(['weight' => 70]);
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/assessments', $this->body(['title' => 'Exam', 'weight' => 40]))->assertUnprocessable()->assertJsonValidationErrors('weight');
        $this->postJson('/api/assessments', $this->body(['title' => 'Exam', 'weight' => 30]))->assertCreated();
        // Editing an assessment does not count itself twice.
        $first = Assessment::where('title', 'Quiz 1')->first();
        $this->putJson("/api/assessments/{$first->id}", $this->body(['weight' => 70]))->assertOk();
    }

    public function test_the_list_shows_progress_and_filters(): void
    {
        $quiz = $this->assessment();
        $this->assessment(['title' => 'Exam', 'type' => 'exam', 'weight' => 40, 'is_published' => true]);
        AssessmentScore::create(['assessment_id' => $quiz->id, 'student_id' => $this->students[0]->id, 'score' => 15]);
        Sanctum::actingAs($this->teacher);

        $rows = $this->getJson("/api/assessments?classroom_id={$this->room->id}&subject_id={$this->math->id}&term_id={$this->term->id}")
            ->assertOk()->assertJsonCount(2, 'data')->json('data');
        $byTitle = collect($rows)->keyBy('title');
        $this->assertSame(1, $byTitle['Quiz 1']['scored']);
        $this->assertSame(3, $byTitle['Quiz 1']['students']);
        $this->assertTrue($byTitle['Exam']['is_published']);
        $this->getJson('/api/assessments?term_id=999')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_progress_ignores_the_scores_of_deleted_students(): void
    {
        $quiz = $this->assessment();
        foreach ($this->students as $student) {
            AssessmentScore::create(['assessment_id' => $quiz->id, 'student_id' => $student->id, 'score' => 10]);
        }
        $this->students[2]->delete();
        Sanctum::actingAs($this->teacher);

        $row = $this->getJson('/api/assessments')->assertOk()->json('data.0');
        $this->assertSame(2, $row['students']);
        $this->assertSame(2, $row['scored']);
    }

    public function test_a_teacher_does_not_see_the_assessments_of_another_teacher(): void
    {
        $this->assessment();
        $other = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        Sanctum::actingAs($other);

        $this->getJson('/api/assessments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/assessments/'.Assessment::first()->id)->assertForbidden();
    }

    public function test_the_score_sheet_lists_every_student_with_their_score(): void
    {
        $quiz = $this->assessment();
        AssessmentScore::create(['assessment_id' => $quiz->id, 'student_id' => $this->students[1]->id, 'score' => 17.5, 'remark' => 'Good']);
        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/assessments/{$quiz->id}")->assertOk()
            ->assertJsonPath('data.assessment.title', 'Quiz 1')
            ->assertJsonCount(3, 'data.students')
            ->assertJsonPath('data.students.1.score', 17.5)
            ->assertJsonPath('data.students.1.remark', 'Good')
            ->assertJsonPath('data.students.0.score', null);
    }

    public function test_the_teacher_saves_scores_and_can_correct_them(): void
    {
        $quiz = $this->assessment();
        Sanctum::actingAs($this->teacher);

        $this->putJson("/api/assessments/{$quiz->id}/scores", ['scores' => [
            ['student_id' => $this->students[0]->id, 'score' => 18],
            ['student_id' => $this->students[1]->id, 'score' => 0],
            ['student_id' => $this->students[2]->id, 'score' => null, 'remark' => 'Absent'],
        ]])->assertOk();

        $this->assertSame(3, AssessmentScore::count());
        $this->assertEquals(0, AssessmentScore::where('student_id', $this->students[1]->id)->value('score'), 'zero is a real score');
        $this->assertNull(AssessmentScore::where('student_id', $this->students[2]->id)->value('score'));

        $this->putJson("/api/assessments/{$quiz->id}/scores", ['scores' => [['student_id' => $this->students[0]->id, 'score' => 19.5]]])->assertOk();
        $this->assertSame(3, AssessmentScore::count());
        $this->assertEquals(19.5, AssessmentScore::where('student_id', $this->students[0]->id)->value('score'));
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => AssessmentScore::class, 'action' => 'updated']);
    }

    public function test_scores_are_validated(): void
    {
        $quiz = $this->assessment();
        $stranger = Student::create(['student_number' => 'X', 'name_ar' => 'غريب', 'gender' => 'male']);
        Sanctum::actingAs($this->teacher);
        $put = fn (array $scores) => $this->putJson("/api/assessments/{$quiz->id}/scores", ['scores' => $scores]);

        $put([['student_id' => $this->students[0]->id, 'score' => 21]])->assertUnprocessable()->assertJsonValidationErrors('scores.0.score');
        $put([['student_id' => $this->students[0]->id, 'score' => -1]])->assertUnprocessable();
        $put([['student_id' => $this->students[0]->id, 'score' => 'abc']])->assertUnprocessable();
        $put([['student_id' => $stranger->id, 'score' => 5]])->assertUnprocessable()->assertJsonValidationErrors('scores.0.student_id');
        $put([['student_id' => $this->students[0]->id, 'score' => 5], ['student_id' => $this->students[0]->id, 'score' => 6]])->assertUnprocessable();
        $this->assertSame(0, AssessmentScore::count());
    }

    public function test_publishing_and_unpublishing(): void
    {
        $quiz = $this->assessment();
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/assessments/{$quiz->id}/publish")->assertOk()->assertJsonPath('data.is_published', true);
        $this->assertTrue($quiz->fresh()->is_published);
        $this->postJson("/api/assessments/{$quiz->id}/unpublish")->assertOk()->assertJsonPath('data.is_published', false);
    }

    public function test_only_an_unpublished_assessment_can_be_deleted(): void
    {
        $quiz = $this->assessment(['is_published' => true]);
        Sanctum::actingAs($this->teacher);

        $this->deleteJson("/api/assessments/{$quiz->id}")->assertUnprocessable();
        $this->postJson("/api/assessments/{$quiz->id}/unpublish")->assertOk();
        $this->deleteJson("/api/assessments/{$quiz->id}")->assertNoContent();
        $this->assertSame(0, Assessment::count());
    }

    public function test_changing_the_max_score_below_an_existing_score_is_refused(): void
    {
        $quiz = $this->assessment();
        AssessmentScore::create(['assessment_id' => $quiz->id, 'student_id' => $this->students[0]->id, 'score' => 18]);
        Sanctum::actingAs($this->teacher);

        $this->putJson("/api/assessments/{$quiz->id}", $this->body(['max_score' => 15]))->assertUnprocessable()->assertJsonValidationErrors('max_score');
        $this->putJson("/api/assessments/{$quiz->id}", $this->body(['max_score' => 25]))->assertOk();
    }

    public function test_an_admin_may_manage_any_assessment_but_students_and_parents_may_not(): void
    {
        $quiz = $this->assessment();

        $this->actAsAdmin();
        $this->getJson("/api/assessments/{$quiz->id}")->assertOk();
        $this->putJson("/api/assessments/{$quiz->id}/scores", ['scores' => [['student_id' => $this->students[0]->id, 'score' => 10]]])->assertOk();

        foreach ([Role::Student, Role::Guardian] as $role) {
            Sanctum::actingAs(User::factory()->role($role)->create());
            $this->getJson('/api/assessments')->assertForbidden();
            $this->getJson('/api/grading/options')->assertForbidden();
            $this->putJson("/api/assessments/{$quiz->id}/scores", ['scores' => []])->assertForbidden();
        }
    }

    public function test_a_student_can_be_marked_absent_to_take_a_makeup_exam_later(): void
    {
        $quiz = $this->assessment();
        Sanctum::actingAs($this->teacher);

        $this->putJson("/api/assessments/{$quiz->id}/scores", ['scores' => [
            ['student_id' => $this->students[0]->id, 'score' => 15],
            ['student_id' => $this->students[1]->id, 'score' => null, 'status' => 'absent', 'remark' => 'Sick'],
        ]])->assertOk();

        $this->assertDatabaseHas('assessment_scores', ['student_id' => $this->students[1]->id, 'status' => 'absent', 'score' => null]);
        $row = $this->getJson('/api/assessments')->json('data.0');
        $this->assertSame(1, $row['absent'], 'the list reminds the teacher how many makeup exams are pending');
        $this->assertSame(1, $row['scored']);

        // The makeup exam is taken the next day: the score arrives and the status says so.
        $this->putJson("/api/assessments/{$quiz->id}/scores", ['scores' => [
            ['student_id' => $this->students[1]->id, 'score' => 12, 'status' => 'makeup', 'remark' => 'Makeup exam'],
        ]])->assertOk();

        $sheet = $this->getJson("/api/assessments/{$quiz->id}")->json('data.students.1');
        $this->assertSame('makeup', $sheet['status']);
        $this->assertEquals(12, $sheet['score']);
        $this->assertSame(0, $this->getJson('/api/assessments')->json('data.0.absent'));
    }

    public function test_the_status_rules(): void
    {
        $quiz = $this->assessment();
        Sanctum::actingAs($this->teacher);
        $put = fn (array $row) => $this->putJson("/api/assessments/{$quiz->id}/scores", ['scores' => [['student_id' => $this->students[0]->id, ...$row]]]);

        $put(['score' => 10, 'status' => 'absent'])->assertUnprocessable()->assertJsonValidationErrors('scores.0.status');
        $put(['score' => 10, 'status' => 'late'])->assertUnprocessable();
        $put(['score' => null, 'status' => 'absent'])->assertOk();
        $put(['score' => 10, 'status' => null])->assertOk();
        $this->assertNull(AssessmentScore::first()->status);
    }

    public function test_the_score_sheet_tells_who_was_absent_that_day_in_the_attendance_and_the_pass_mark(): void
    {
        $quiz = $this->assessment(['held_on' => '2026-10-05']);
        DB::table('student_attendances')->insert([
            'student_id' => $this->students[1]->id, 'classroom_id' => $this->room->id, 'date' => '2026-10-05', 'status' => 'absent',
            'marked_by' => $this->teacher->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($this->teacher);

        $data = $this->getJson("/api/assessments/{$quiz->id}")->assertOk()->json('data');

        $this->assertEquals(50, $data['pass_percent']);
        $this->assertFalse($data['students'][0]['was_absent']);
        $this->assertTrue($data['students'][1]['was_absent']);
    }

    public function test_a_failed_student_gets_a_retake_and_the_first_score_is_kept(): void
    {
        $exam = $this->assessment(['type' => 'exam', 'max_score' => 100]);
        AssessmentScore::create(['assessment_id' => $exam->id, 'student_id' => $this->students[0]->id, 'score' => 38]);
        Sanctum::actingAs($this->teacher);

        // Marked for a retake: the failing score is set aside, the exam waits for the new score.
        $this->putJson("/api/assessments/{$exam->id}/scores", ['scores' => [['student_id' => $this->students[0]->id, 'score' => null, 'status' => 'retake']]])->assertOk();
        $row = AssessmentScore::first();
        $this->assertSame('retake', $row->status);
        $this->assertNull($row->score);
        $this->assertEquals(38, $row->first_score);
        $this->assertSame(1, $this->getJson('/api/assessments')->json('data.0.absent'), 'a retake waiting for its score is pending too');

        // The retake is taken: the new score arrives, the first one stays.
        $this->putJson("/api/assessments/{$exam->id}/scores", ['scores' => [['student_id' => $this->students[0]->id, 'score' => 51, 'status' => 'retake']]])->assertOk();
        $row->refresh();
        $this->assertEquals(51, $row->score);
        $this->assertEquals(38, $row->first_score);

        $sheet = $this->getJson("/api/assessments/{$exam->id}")->json('data.students.0');
        $this->assertSame('retake', $sheet['status']);
        $this->assertEquals(38, $sheet['first_score']);
    }
}
