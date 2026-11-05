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
use App\Shared\Enums\StaffType;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Feature\Academic\AcademicTestCase;

class MarksImportTest extends AcademicTestCase
{
    private Classroom $room;

    private Subject $math;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsAdmin();
        $this->year = AcademicYear::factory()->create(['is_current' => true, 'name' => '2026-2027']);
        Term::create(['academic_year_id' => $this->year->id, 'name' => 'First term', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31']);
        Term::create(['academic_year_id' => $this->year->id, 'name' => 'Second term', 'starts_on' => '2027-02-01', 'ends_on' => '2027-06-30']);
        $this->math = Subject::factory()->create(['code' => 'MATH', 'name_en' => 'Math']);
        $teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create();
        $this->room = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $this->year->id]);
        TeachingAssignment::create(['classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'teacher_id' => $teacher->id, 'weekly_periods' => 4]);
        foreach ([1, 2] as $n) {
            $s = Student::create(['student_number' => "S-$n", 'name_ar' => "ط$n", 'name_en' => "S$n", 'gender' => 'male']);
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $this->year->id]);
        }
    }

    private function upload(array $rows, bool $confirm = true)
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $this->post('/api/import/marks', ['file' => new UploadedFile($path, 'marks.xlsx', null, null, true), 'confirm' => $confirm ? '1' : '0'], ['Accept' => 'application/json']);
    }

    private const HEAD = ['Student number', 'Subject', 'Term', 'Assessment', 'Out of', 'Mark', 'Weight', 'Type'];

    public function test_marks_create_the_assessment_once_and_a_score_per_student(): void
    {
        $this->upload([
            self::HEAD,
            ['S-1', 'MATH', 'First term', 'Midterm', 20, 17.5, 30, 'exam'],
            ['S-2', 'Math', 'First term', 'Midterm', 20, 12, 30, 'exam'],
            ['S-1', 'MATH', '1', 'Quiz 1', 10, 9, 10, 'quiz'],
        ])->assertOk()->assertJsonPath('data.valid', 3)->assertJsonPath('data.problem_count', 0);

        $this->assertSame(2, Assessment::count());
        $mid = Assessment::where('title', 'Midterm')->first();
        $this->assertSame('exam', $mid->type);
        $this->assertTrue($mid->is_published);
        $this->assertEqualsWithDelta(30.0, $mid->weight, 0.001);
        $this->assertSame(2, $mid->scores()->count());
        $this->assertEqualsWithDelta(17.5, (float) AssessmentScore::where('assessment_id', $mid->id)->whereHas('student', fn ($q) => $q->where('student_number', 'S-1'))->value('score'), 0.001);
    }

    public function test_importing_the_same_file_again_updates_marks_instead_of_duplicating(): void
    {
        $rows = [self::HEAD, ['S-1', 'MATH', 'First term', 'Midterm', 20, 10, 30, 'exam']];
        $this->upload($rows)->assertOk();
        $rows[1][5] = 15;
        $this->upload($rows)->assertOk()->assertJsonPath('data.problem_count', 0);

        $this->assertSame(1, Assessment::count());
        $this->assertSame(1, AssessmentScore::count());
        $this->assertEqualsWithDelta(15.0, (float) AssessmentScore::first()->score, 0.001);
    }

    public function test_absent_status_needs_no_mark_and_hidden_marks_stay_unpublished(): void
    {
        $this->upload([
            ['Student number', 'Subject', 'Term', 'Assessment', 'Out of', 'Mark', 'Status', 'Visible to parents'],
            ['S-1', 'MATH', 'First term', 'Exam', 20, '', 'غائب', 'no'],
        ])->assertOk()->assertJsonPath('data.problem_count', 0);

        $score = AssessmentScore::first();
        $this->assertNull($score->score);
        $this->assertSame('absent', $score->status);
        $this->assertFalse(Assessment::first()->is_published);
    }

    public function test_every_bad_row_is_reported_by_its_reason(): void
    {
        $response = $this->upload([
            self::HEAD,
            ['NOPE', 'MATH', 'First term', 'Exam', 20, 10, 0, 'exam'],        // unknown student
            ['S-1', 'XXX', 'First term', 'Exam', 20, 10, 0, 'exam'],          // unknown subject
            ['S-1', 'MATH', 'Third term', 'Exam', 20, 10, 0, 'exam'],         // unknown term
            ['S-1', 'MATH', 'First term', 'Exam', 20, 25, 0, 'exam'],         // above the maximum
            ['S-1', 'MATH', 'First term', 'Exam', 20, 'abc', 0, 'exam'],      // not a number
            ['S-1', 'MATH', 'First term', 'Exam', 20, 10, 0, 'dance'],        // unknown type
            ['S-1', 'MATH', 'First term', 'Big', 20, 10, 150, 'exam'],        // weight above 100
        ], false)->assertOk();

        $response->assertJsonPath('data.valid', 0)->assertJsonPath('data.problem_count', 7);
        $this->assertSame(0, Assessment::count());
    }

    public function test_weights_over_100_and_a_title_with_two_different_maximums_are_refused(): void
    {
        $response = $this->upload([
            self::HEAD,
            ['S-1', 'MATH', 'First term', 'Midterm', 20, 10, 70, 'exam'],
            ['S-1', 'MATH', 'First term', 'Final', 50, 40, 40, 'exam'],   // 70 + 40 > 100
            ['S-2', 'MATH', 'First term', 'Midterm', 30, 10, 70, 'exam'], // same title, another maximum
        ], false)->assertOk();

        $response->assertJsonPath('data.valid', 1)->assertJsonPath('data.problem_count', 2);
    }

    public function test_a_previous_year_is_graded_in_the_section_the_student_sat_in_then(): void
    {
        $old = AcademicYear::factory()->create(['name' => '2025-2026', 'is_current' => false]);
        Term::create(['academic_year_id' => $old->id, 'name' => 'First term', 'starts_on' => '2025-09-01', 'ends_on' => '2026-01-31']);
        $oldRoom = Classroom::factory()->create(['name' => '4-A', 'academic_year_id' => $old->id]);
        TeachingAssignment::create(['classroom_id' => $oldRoom->id, 'subject_id' => $this->math->id, 'teacher_id' => User::factory()->staff(StaffType::Teacher, ['subject_id' => $this->math->id])->create()->id, 'weekly_periods' => 4]);
        Enrollment::create(['student_id' => Student::where('student_number', 'S-1')->value('id'), 'classroom_id' => $oldRoom->id, 'academic_year_id' => $old->id]);

        $this->upload([
            [...self::HEAD, 'Academic year'],
            ['S-1', 'MATH', 'First term', 'Final', 100, 80, 0, 'exam', '2025-2026'],
            ['S-2', 'MATH', 'First term', 'Final', 100, 80, 0, 'exam', '2025-2026'], // S-2 had no section that year
        ])->assertOk()->assertJsonPath('data.valid', 1)->assertJsonPath('data.problem_count', 1);

        $this->assertSame($oldRoom->id, Assessment::first()->classroom_id);
    }

    public function test_a_subject_nobody_teaches_in_that_section_is_refused(): void
    {
        $art = Subject::factory()->create(['code' => 'ART', 'name_en' => 'Art']);

        $this->upload([self::HEAD, ['S-1', 'ART', 'First term', 'Exam', 20, 10, 0, 'exam']], false)->assertOk()->assertJsonPath('data.problem_count', 1);
        $this->assertNotNull($art);
    }

    public function test_the_template_downloads_and_non_admins_are_refused(): void
    {
        $this->get('/api/import/marks/template')->assertOk();

        \Laravel\Sanctum\Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->post('/api/import/marks', [], ['Accept' => 'application/json'])->assertForbidden();
    }
}
