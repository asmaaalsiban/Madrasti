<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Shared\Enums\StaffType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Feature\Academic\AcademicTestCase;

class AttendanceImportTest extends AcademicTestCase
{
    private Classroom $room;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->actAsAdmin();
        $year = AcademicYear::factory()->create(['is_current' => true, 'starts_on' => now()->subMonths(3)->toDateString(), 'ends_on' => now()->addMonths(6)->toDateString()]);
        $this->room = Classroom::factory()->create(['academic_year_id' => $year->id]);
        foreach ([1, 2] as $n) {
            $s = Student::create(['student_number' => "S-$n", 'name_ar' => "ط$n", 'name_en' => "S$n", 'gender' => 'male']);
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $this->room->id, 'academic_year_id' => $year->id]);
        }
    }

    private function day(int $ago): string
    {
        return now()->subDays($ago)->toDateString();
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

        return $this->post('/api/import/attendance', ['file' => new UploadedFile($path, 'att.xlsx', null, null, true), 'confirm' => $confirm ? '1' : '0'], ['Accept' => 'application/json']);
    }

    public function test_past_days_are_recorded_in_the_students_section_and_no_parent_is_told(): void
    {
        $this->upload([
            ['Student number', 'Date', 'Status', 'Note'],
            ['S-1', $this->day(3), 'غائب', 'sick'],
            ['S-2', $this->day(3), 'present', ''],
            ['S-1', $this->day(2), 'متأخر', ''],
        ])->assertOk()->assertJsonPath('data.valid', 3)->assertJsonPath('data.problem_count', 0);

        $this->assertSame(3, StudentAttendance::count());
        $first = StudentAttendance::whereHas('student', fn ($q) => $q->where('student_number', 'S-1'))->where('date', $this->day(3))->first();
        $this->assertSame('absent', $first->status->value);
        $this->assertSame($this->room->id, $first->classroom_id);
        $this->assertSame('sick', $first->note);
        Queue::assertNothingPushed();
    }

    public function test_importing_a_day_again_corrects_it(): void
    {
        $this->upload([['Student number', 'Date', 'Status'], ['S-1', $this->day(1), 'absent']])->assertOk();
        $this->upload([['Student number', 'Date', 'Status'], ['S-1', $this->day(1), 'excused']])->assertOk()->assertJsonPath('data.problem_count', 0);

        $this->assertSame(1, StudentAttendance::count());
        $this->assertSame('excused', StudentAttendance::first()->status->value);
    }

    public function test_bad_rows_are_reported(): void
    {
        $response = $this->upload([
            ['Student number', 'Date', 'Status'],
            ['NOPE', $this->day(1), 'absent'],                  // unknown student
            ['S-1', 'not a date', 'absent'],                    // bad date
            ['S-1', now()->addDays(3)->toDateString(), 'absent'], // future
            ['S-1', $this->day(1), 'sleeping'],                 // bad status
            ['S-1', now()->subYears(5)->toDateString(), 'absent'], // outside any year
            ['S-2', $this->day(1), 'absent'],
            ['S-2', $this->day(1), 'present'],                  // twice in the file
        ], false)->assertOk();

        $response->assertJsonPath('data.valid', 1)->assertJsonPath('data.problem_count', 6);
        $this->assertSame(0, StudentAttendance::count());
    }

    public function test_the_template_downloads_and_non_admins_are_refused(): void
    {
        $this->get('/api/import/attendance/template')->assertOk();
        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->post('/api/import/attendance', [], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_single_arabic_letters_are_understood(): void
    {
        $this->upload([['Student number', 'Date', 'Status'], ['S-1', $this->day(1), 'غ'], ['S-2', $this->day(1), 'ح'], ['S-1', $this->day(2), 'ت'], ['S-2', $this->day(2), 'ع']])
            ->assertOk()->assertJsonPath('data.problem_count', 0);

        $this->assertSame(['absent', 'present', 'late', 'excused'], StudentAttendance::orderBy('id')->get()->map(fn ($a) => $a->status->value)->all());
    }
}
