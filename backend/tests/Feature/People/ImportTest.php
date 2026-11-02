<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\Subject;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class ImportTest extends AcademicTestCase
{
    private Classroom $room;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true]);
        $this->room = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        $this->actAsAdmin();
    }

    /** @param list<list<mixed>> $rows */
    private function sheet(array $rows, string $name = 'file.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    private function upload(string $kind, UploadedFile $file, bool $confirm = false)
    {
        return $this->post("/api/import/$kind", ['file' => $file, 'confirm' => $confirm ? '1' : '0'], ['Accept' => 'application/json']);
    }

    private function students(): array
    {
        return [
            ['Student number', 'Name (Arabic)', 'Name (English)', 'Birth date', 'Gender', 'Section', 'Parent name', 'Parent email', 'Parent phone'],
            [2026001, 'ليلى أحمد', 'Layla Ahmad', '2015-03-21', 'female', '5-A', 'Ahmad Ali', 'Ahmad@Example.com', '0790000000'],
            [2026002, 'سامي أحمد', 'Sami Ahmad', '2013-01-05', 'ذكر', '5-A', 'Ahmad Ali', 'ahmad@example.com', '0790000000'], // same parent: two children
        ];
    }

    public function test_the_preview_checks_every_row_and_creates_nothing(): void
    {
        $rows = $this->students();
        $rows[] = [2026003, 'خالد', null, null, 'unknown', '9-Z', null, null, null]; // bad gender, unknown section
        $rows[] = [2026001, 'مكرر', null, null, null, null, null, null, null]; // student number repeated in the file

        $data = $this->upload('students', $this->sheet($rows))->assertOk()->json('data');

        $this->assertSame(4, $data['total']);
        $this->assertSame(2, $data['valid']);
        $this->assertNull($data['created']);
        $this->assertSame(2, $data['problem_count']);
        $this->assertSame([4, 5], collect($data['problems'])->pluck('line')->all());
        $this->assertCount(2, $data['problems'][0]['errors']);
        $this->assertSame(0, Student::count());
    }

    public function test_confirming_creates_the_valid_rows_with_accounts_class_and_one_shared_parent(): void
    {
        $rows = [...$this->students(), [2026003, 'خالد', null, null, 'x', null, null, null, null]];
        $data = $this->upload('students', $this->sheet($rows), true)->assertOk()->json('data');

        $this->assertCount(2, $data['created']);
        $this->assertSame(1, $data['problem_count']);
        $this->assertSame(2, Student::count());
        $layla = Student::where('student_number', '2026001')->firstOrFail();
        $this->assertSame($this->room->id, $layla->enrollments()->first()->classroom_id);
        $this->assertNotNull($layla->user_id);
        $this->assertSame(Role::Student, $layla->user->role);
        $this->assertSame(1, User::where('role', Role::Guardian)->count()); // the same parent email is one account
        $this->assertSame(2, User::where('email', 'ahmad@example.com')->first()->children()->count());
        $this->assertSame($layla->user->email, $data['created'][0]['email']);
    }

    public function test_importing_the_same_file_again_creates_nothing_new(): void
    {
        $this->upload('students', $this->sheet($this->students()), true)->assertOk();
        $again = $this->upload('students', $this->sheet($this->students()), true)->assertOk()->json('data');

        $this->assertSame([], $again['created']);
        $this->assertSame(2, $again['problem_count']);
        $this->assertSame(2, Student::count());
    }

    public function test_columns_can_be_named_in_arabic_and_a_csv_file_works(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "رقم الطالب,الاسم بالعربي\n77,علي\n78,عمر\n");
        $file = new UploadedFile($path, 'students.csv', null, null, true);

        $this->upload('students', $file, true)->assertOk()->assertJsonPath('data.created.0.name', 'علي');
        $this->assertSame(2, Student::count());
    }

    public function test_a_file_without_the_required_columns_or_rows_is_refused(): void
    {
        $this->upload('students', $this->sheet([['Name (Arabic)'], ['علي']]))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload('students', $this->sheet([['Student number', 'Name (Arabic)']]))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload('students', UploadedFile::fake()->create('x.pdf', 10))->assertUnprocessable();
    }

    public function test_staff_are_imported_with_their_position_and_subject(): void
    {
        $math = Subject::factory()->create(['name_en' => 'Math', 'name_ar' => 'رياضيات']);
        $rows = [
            ['Name', 'Email', 'Phone', 'Position', 'Subject'],
            ['Sara Khaled', 'Sara@Example.com', '0791111111', 'teacher', 'Math'],
            ['Omar Nasser', 'omar@example.com', null, 'Accountant', null],
            ['No Type', 'notype@example.com', null, 'astronaut', null],
            ['Bad Subject', 'bs@example.com', null, 'teacher', 'Alchemy'],
            ['Dup', 'sara@example.com', null, 'teacher', 'Math'],
        ];
        $preview = $this->upload('staff', $this->sheet($rows))->assertOk()->json('data');
        $this->assertSame([2, 3], [$preview['valid'], $preview['problem_count']]);
        $this->assertSame(0, User::where('email', 'sara@example.com')->count());

        $this->upload('staff', $this->sheet($rows), true)->assertOk()->assertJsonCount(2, 'data.created');
        $sara = User::where('email', 'sara@example.com')->firstOrFail();
        $this->assertSame($math->id, $sara->staffProfile->subject_id);
        $this->assertSame(StaffType::Accountant, User::where('email', 'omar@example.com')->first()->staffProfile->staff_type);
        $this->assertTrue($sara->must_change_password);
    }

    public function test_the_template_has_the_columns_in_the_interface_language(): void
    {
        $response = $this->get('/api/import/students/template', ['Accept-Language' => 'en'])->assertOk();
        $reader = new Reader();
        $reader->open($response->baseResponse->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        $this->assertSame('Student number', $rows[0][0]);
        $this->assertCount(2, $rows); // header + example

        $this->get('/api/import/nope/template')->assertNotFound();
    }

    public function test_only_the_admin_can_import(): void
    {
        foreach ([User::factory()->staff(StaffType::Teacher)->create(), User::factory()->role(Role::Guardian)->create()] as $user) {
            Sanctum::actingAs($user);
            $this->upload('students', $this->sheet($this->students()), true)->assertForbidden();
            $this->get('/api/import/students/template')->assertForbidden();
        }
        $this->assertSame(0, Student::count());
    }
}
