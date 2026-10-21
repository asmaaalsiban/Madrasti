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
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class ResultsTest extends AcademicTestCase
{
    private User $teacher;

    private Classroom $room;

    private Subject $math;

    private Term $term;

    /** @var list<Student> */
    private array $students = [];

    private Assessment $quiz;

    private Assessment $assignment;

    private Assessment $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->term = Term::create(['academic_year_id' => $year->id, 'name' => 'First term', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31']);
        $this->math = Subject::factory()->create();
        $this->teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $this->room = Classroom::factory()->create(['academic_year_id' => $year->id]);
        TeachingAssignment::create(['classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'teacher_id' => $this->teacher->id, 'weekly_periods' => 4]);
        foreach ([1, 2, 3, 4] as $n) {
            $student = Student::create(['student_number' => "S-$n", 'name_ar' => "طالب $n", 'name_en' => "Student $n", 'gender' => 'male']);
            Enrollment::create(['student_id' => $student->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $year->id]);
            $this->students[] = $student;
        }
        $make = fn (string $title, int $max, int $weight) => Assessment::create([
            'classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'term_id' => $this->term->id, 'teacher_id' => $this->teacher->id,
            'title' => $title, 'type' => 'quiz', 'max_score' => $max, 'weight' => $weight, 'is_published' => true,
        ]);
        $this->quiz = $make('Quiz', 20, 20);
        $this->assignment = $make('Assignment', 10, 20);
        $this->exam = $make('Exam', 100, 60);
    }

    private function score(Assessment $assessment, Student $student, ?float $score, ?string $status = null): void
    {
        AssessmentScore::create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'score' => $score, 'status' => $status]);
    }

    private function results(array $query = []): array
    {
        $query = [...['classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'term_id' => $this->term->id], ...$query];

        return $this->getJson('/api/grading/results?'.http_build_query($query))->assertOk()->json('data');
    }

    public function test_the_percentage_is_weighted_and_the_pass_mark_is_fifty_percent(): void
    {
        Sanctum::actingAs($this->teacher);
        // Student 1: 90% x20 + 50% x20 + 40% x60 = 52 -> pass
        $this->score($this->quiz, $this->students[0], 18);
        $this->score($this->assignment, $this->students[0], 5);
        $this->score($this->exam, $this->students[0], 40);
        // Student 2: 30% everywhere -> fail
        $this->score($this->quiz, $this->students[1], 6);
        $this->score($this->assignment, $this->students[1], 3);
        $this->score($this->exam, $this->students[1], 30);
        // Student 3: exactly 50% -> pass
        $this->score($this->exam, $this->students[2], 50);

        $data = $this->results();
        $rows = collect($data['students'])->keyBy('student.id');

        $this->assertSame(50.0, (float) $data['pass_percent']);
        $this->assertEqualsWithDelta(52.0, $rows[$this->students[0]->id]['percent'], 0.01);
        $this->assertTrue($rows[$this->students[0]->id]['passed']);
        $this->assertEqualsWithDelta(30.0, $rows[$this->students[1]->id]['percent'], 0.01);
        $this->assertFalse($rows[$this->students[1]->id]['passed']);
        $this->assertEqualsWithDelta(50.0, $rows[$this->students[2]->id]['percent'], 0.01);
        $this->assertTrue($rows[$this->students[2]->id]['passed']);
        $this->assertSame(3, $rows[$this->students[0]->id]['graded']);
        $this->assertNull($rows[$this->students[3]->id]['percent'], 'nothing graded yet');
        $this->assertNull($rows[$this->students[3]->id]['passed']);
    }

    public function test_an_absent_student_waiting_for_a_makeup_exam_is_pending_not_failing(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->score($this->quiz, $this->students[0], 18);
        $this->score($this->exam, $this->students[0], null, 'absent');

        $row = collect($this->results()['students'])->firstWhere('student.id', $this->students[0]->id);

        $this->assertEqualsWithDelta(90.0, $row['percent'], 0.01, 'only the graded assessments count');
        $this->assertSame(1, $row['pending']);
        $this->assertSame(1, $row['graded']);
    }

    public function test_a_makeup_score_counts_like_any_other_score(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->score($this->exam, $this->students[0], 80, 'makeup');

        $row = collect($this->results()['students'])->firstWhere('student.id', $this->students[0]->id);

        $this->assertEqualsWithDelta(80.0, $row['percent'], 0.01);
        $this->assertSame(0, $row['pending']);
    }

    public function test_deleted_students_are_not_listed_and_the_order_is_by_name(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->students[3]->delete();

        $names = collect($this->results()['students'])->pluck('student.name_ar')->all();

        $this->assertSame(['طالب 1', 'طالب 2', 'طالب 3'], $names);
    }

    public function test_only_the_teacher_of_the_subject_and_admins_may_see_the_results(): void
    {
        $other = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $query = http_build_query(['classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'term_id' => $this->term->id]);

        Sanctum::actingAs($other);
        $this->getJson("/api/grading/results?$query")->assertForbidden();

        $this->actAsAdmin();
        $this->getJson("/api/grading/results?$query")->assertOk();

        foreach ([Role::Student, Role::Guardian] as $role) {
            Sanctum::actingAs(User::factory()->role($role)->create());
            $this->getJson("/api/grading/results?$query")->assertForbidden();
        }
    }

    public function test_the_filters_are_required(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/grading/results')->assertForbidden();
        $this->getJson('/api/grading/results?classroom_id=abc&subject_id=1&term_id=1')->assertForbidden();
    }
}
