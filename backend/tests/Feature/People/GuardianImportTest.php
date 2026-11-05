<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Feature\Academic\AcademicTestCase;

class GuardianImportTest extends AcademicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsAdmin();
        foreach (['1001', '1002', 'S-3'] as $n) {
            Student::create(['student_number' => $n, 'name_ar' => "ط$n", 'name_en' => "S$n", 'gender' => 'male']);
        }
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

        return new UploadedFile($path, 'parents.xlsx', null, null, true);
    }

    private function upload(array $rows, bool $confirm = true)
    {
        return $this->post('/api/import/guardians', ['file' => $this->sheet($rows), 'confirm' => $confirm ? '1' : '0'], ['Accept' => 'application/json']);
    }

    public function test_parents_are_created_and_linked_to_their_children_by_student_number(): void
    {
        $this->upload([
            ['Parent name', 'Phone', 'Email', 'Student numbers'],
            ['Ahmad Ali', '0790000001', '', '1001, 1002'],
            ['Mona Ali', '0790000002', 'mona@example.com', 'S-3'],
        ])->assertOk()->assertJsonPath('data.valid', 2)->assertJsonPath('data.problem_count', 0);

        $ahmad = User::findByLogin('0790000001');
        $this->assertSame(Role::Guardian, $ahmad->role);
        $this->assertNull($ahmad->email);
        $this->assertSame(2, $ahmad->children()->count());
        $this->assertSame('mona@example.com', User::findByLogin('mona@example.com')->email);
    }

    public function test_a_second_parent_of_the_same_child_is_linked_and_an_existing_parent_is_reused(): void
    {
        $this->upload([['Parent name', 'Phone', 'Student numbers'], ['Dad', '0790000001', '1001']])->assertOk();
        $this->upload([['Parent name', 'Phone', 'Student numbers'], ['Mum', '0790000009', '1001'], ['Dad again', '0790000001', '1002']])->assertOk()->assertJsonPath('data.problem_count', 0);

        $this->assertSame(2, Student::where('student_number', '1001')->first()->guardians()->count());
        $this->assertSame(2, User::findByLogin('0790000001')->children()->count());
        $this->assertSame(2, User::where('role', Role::Guardian)->count());
    }

    public function test_bad_rows_are_reported_and_the_good_ones_still_import(): void
    {
        $response = $this->upload([
            ['Parent name', 'Phone', 'Email', 'Student numbers'],
            ['No phone', '', '', '1001'],
            ['Wrong child', '0790000003', '', '9999'],
            ['Bad email', '0790000004', 'nope', '1001'],
            ['Fine', '0790000005', '', '1002'],
        ])->assertOk();

        $response->assertJsonPath('data.valid', 1)->assertJsonPath('data.problem_count', 3);
        $this->assertNotNull(User::findByLogin('0790000005'));
        $this->assertNull(User::findByLogin('0790000003'));
    }

    public function test_the_phone_of_a_staff_account_is_refused(): void
    {
        User::factory()->staff()->create(['phone' => '+962790000007']);

        $this->upload([['Parent name', 'Phone', 'Student numbers'], ['Someone', '0790000007', '1001']], false)->assertOk()->assertJsonPath('data.problem_count', 1);
    }

    public function test_the_preview_creates_nothing_and_the_template_downloads(): void
    {
        $this->upload([['Parent name', 'Phone', 'Student numbers'], ['Dad', '0790000001', '1001']], false)->assertOk()->assertJsonPath('data.valid', 1);
        $this->assertNull(User::findByLogin('0790000001'));

        $this->get('/api/import/guardians/template')->assertOk();
    }

    public function test_children_written_with_the_word_and_and_the_arabic_column_name_are_understood(): void
    {
        $this->upload([['اسم ولي الأمر', 'رقم الجوال', 'أبناؤه'], ['خالد', '0790000011', '1001 و 1002']])->assertOk()->assertJsonPath('data.problem_count', 0);

        $this->assertSame(2, User::findByLogin('0790000011')->children()->count());
    }
}
