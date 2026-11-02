<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Models\Term;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Finance\Services\FeeBilling;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use OpenSpout\Reader\XLSX\Reader;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class ExportTest extends AcademicTestCase
{
    private AcademicYear $year;

    private Classroom $room;

    private Student $ali;

    private Student $omar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->room = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $this->year->id]);
        $this->ali = $this->enroll('S-1', 'علي', 'Ali');
        $this->omar = $this->enroll('S-2', 'عمر', 'Omar');
    }

    private function enroll(string $no, string $ar, string $en): Student
    {
        $s = Student::create(['student_number' => $no, 'name_ar' => $ar, 'name_en' => $en, 'gender' => 'male']);
        Enrollment::create(['student_id' => $s->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $this->year->id]);

        return $s;
    }

    /** @return list<list<mixed>> the rows of the downloaded workbook */
    private function rows($response): array
    {
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $reader = new Reader();
        $reader->open($response->baseResponse->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }

    public function test_the_attendance_report_downloads_as_excel_with_every_row(): void
    {
        foreach (['2026-10-04', '2026-10-05'] as $day) {
            StudentAttendance::create(['student_id' => $this->ali->id, 'classroom_id' => $this->room->id, 'date' => $day, 'status' => 'absent', 'marked_by' => $this->actAsAdmin()->id]);
        }
        StudentAttendance::create(['student_id' => $this->omar->id, 'classroom_id' => $this->room->id, 'date' => '2026-10-04', 'status' => 'late', 'marked_by' => User::first()->id]);

        $rows = $this->rows($this->get('/api/attendance/report/export?classroom_id='.$this->room->id, ['Accept-Language' => 'en']));
        $this->assertSame(['Student number', 'Name (Arabic)', 'Name (English)', 'Section', 'Days absent', 'Days late'], $rows[0]);
        $this->assertSame(['S-1', 'علي', 'Ali', '5-A', 2, 0], $rows[1]);
        $this->assertSame(['S-2', 'عمر', 'Omar', '5-A', 0, 1], $rows[2]);
        $this->assertCount(3, $rows);
    }

    public function test_the_headers_follow_the_language(): void
    {
        $this->actAsAdmin();
        $rows = $this->rows($this->get('/api/attendance/report/export', ['Accept-Language' => 'ar']));
        $this->assertSame('رقم الطالب', $rows[0][0]);
    }

    public function test_a_teacher_cannot_export_another_classrooms_attendance(): void
    {
        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->get('/api/attendance/report/export?classroom_id='.$this->room->id)->assertForbidden();
    }

    public function test_the_accounts_list_downloads_with_amounts_and_respects_the_filters(): void
    {
        $billing = app(FeeBilling::class);
        $fee = $billing->createFee($this->ali, $this->year, 'Tuition', 700, 0, $billing->evenSchedule(70000, 4, '2026-09-01'));
        $billing->recordPayment($fee, 175, 'cash', now()->toDateString(), $this->actAsAdmin());
        $accountant = User::factory()->staff(StaffType::Accountant)->create();
        Sanctum::actingAs($accountant);

        $rows = $this->rows($this->get('/api/finance/accounts/export', ['Accept-Language' => 'en']));
        $this->assertSame('Fees (JOD)', $rows[0][4]);
        $this->assertEquals(['S-1', 'علي', 'Ali', '5-A', 700, 175, 525], array_slice($rows[1], 0, 7));
        $this->assertEquals(['S-2', 0], [$rows[2][0], $rows[2][4]]);

        $owing = $this->rows($this->get('/api/finance/accounts/export?status=owing'));
        $this->assertCount(2, $owing); // header + Ali only

        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->get('/api/finance/accounts/export')->assertForbidden();
        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->get('/api/finance/accounts/export')->assertForbidden();
    }

    public function test_the_results_of_a_subject_download_for_its_teacher_only(): void
    {
        $term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'First term', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']);
        $math = Subject::factory()->create();
        $teacher = User::factory()->staff(StaffType::Teacher, ['subject_id' => $math->id])->create();
        TeachingAssignment::create(['classroom_id' => $this->room->id, 'subject_id' => $math->id, 'teacher_id' => $teacher->id, 'weekly_periods' => 4]);
        $url = "/api/grading/results/export?classroom_id={$this->room->id}&subject_id={$math->id}&term_id={$term->id}";

        Sanctum::actingAs($teacher);
        $rows = $this->rows($this->get($url, ['Accept-Language' => 'en']));
        $this->assertSame('Percent', $rows[0][3]);
        $this->assertSame(['S-1', 'S-2'], [$rows[1][0], $rows[2][0]]);

        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->get($url)->assertForbidden();
        $this->get('/api/grading/results/export')->assertForbidden();
    }
}
