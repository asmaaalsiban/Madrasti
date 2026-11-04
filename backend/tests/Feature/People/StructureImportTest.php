<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\TeachingAssignment;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Feature\Academic\AcademicTestCase;

/** A school's structure from Excel, in the order a migration needs it: grades, subjects, staff, sections, teaching. */
class StructureImportTest extends AcademicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::factory()->create(['is_current' => true]);
        $this->actAsAdmin();
    }

    private function sheet(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'file.xlsx', null, null, true);
    }

    private function upload(string $kind, array $rows, bool $confirm = true)
    {
        return $this->post("/api/import/$kind", ['file' => $this->sheet($rows), 'confirm' => $confirm ? '1' : '0'], ['Accept' => 'application/json']);
    }

    public function test_the_whole_structure_can_be_brought_in_order(): void
    {
        $this->upload('grade_levels', [['Name (Arabic)', 'Name (English)'], ['الصف الخامس', 'Grade 5'], ['الصف السادس', 'Grade 6']])->assertOk()->assertJsonCount(2, 'data.created');
        $this->assertSame([1, 2], GradeLevel::orderBy('sort_order')->pluck('sort_order')->all());

        $this->upload('subjects', [['Code', 'Name (Arabic)', 'Name (English)'], ['MATH', 'الرياضيات', 'Mathematics'], ['ARB', 'العربي', null]])->assertOk()->assertJsonCount(2, 'data.created');
        $this->assertSame('العربي', Subject::where('code', 'ARB')->first()->name_en); // one name only: used for both

        $this->upload('staff', [['Name', 'Email', 'Position', 'Subject'], ['Sara Khaled', 'sara@example.com', 'teacher', 'MATH']])->assertOk()->assertJsonCount(1, 'data.created');

        $rooms = $this->upload('classrooms', [
            ['Section', 'Grade', 'Homeroom teacher', 'Capacity', 'Students'],
            ['5-A', 'الصف الخامس', 'sara@example.com', 30, 'mixed'],
            ['5-B', 'Grade 5', null, null, 'بنات'],
            ['9-A', 'الصف التاسع', null, null, null], // no such grade
        ])->assertOk()->json('data');
        $this->assertCount(2, $rooms['created']);
        $this->assertSame(1, $rooms['problem_count']);
        $this->assertSame('female', Classroom::where('name', '5-B')->first()->gender);
        $this->assertSame('Sara Khaled', Classroom::where('name', '5-A')->first()->homeroomTeacher->name);

        $teach = $this->upload('teaching', [
            ['Section', 'Subject', 'Teacher', 'Periods per week'],
            ['5-A', 'Mathematics', 'sara@example.com', 5],
            ['5-B', 'ARB', 'sara@example.com', 4], // Sara teaches Math, not Arabic
            ['5-B', 'Alchemy', 'sara@example.com', 4],
        ])->assertOk()->json('data');
        $this->assertCount(1, $teach['created']);
        $this->assertSame(2, $teach['problem_count']);
        $this->assertSame(5, TeachingAssignment::first()->weekly_periods);
    }

    public function test_a_second_run_of_the_same_file_creates_nothing(): void
    {
        $rows = [['Name (Arabic)', 'Name (English)'], ['الصف الأول', 'Grade 1']];
        $this->upload('grade_levels', $rows)->assertOk();
        $again = $this->upload('grade_levels', $rows)->assertOk()->json('data');

        $this->assertSame([], $again['created']);
        $this->assertSame(1, $again['problem_count']);
        $this->assertSame(1, GradeLevel::count());

        $subjects = [['Code', 'Name (Arabic)', 'Name (English)'], ['SCI', 'العلوم', 'Science'], ['SCI', 'مكرر', 'Dup']];
        $this->assertSame(1, $this->upload('subjects', $subjects)->json('data.problem_count'));
    }

    public function test_a_preview_creates_nothing_and_every_kind_has_a_template(): void
    {
        $this->upload('grade_levels', [['Name (Arabic)'], ['الصف الأول']], false)->assertOk()->assertJsonPath('data.valid', 1);
        $this->assertSame(0, GradeLevel::count());

        foreach (['grade_levels', 'subjects', 'classrooms', 'teaching'] as $kind) {
            $this->get("/api/import/$kind/template")->assertOk();
        }
        $this->get('/api/import/nothing/template')->assertNotFound();
    }

    public function test_only_the_admin_may_import(): void
    {
        $teacher = User::factory()->staff(\App\Shared\Enums\StaffType::Teacher)->create();
        \Laravel\Sanctum\Sanctum::actingAs($teacher);
        $this->upload('subjects', [['Code', 'Name (Arabic)', 'Name (English)'], ['X', 'س', 'S']])->assertForbidden();
    }
}
