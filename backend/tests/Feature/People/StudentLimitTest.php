<?php

namespace Tests\Feature\People;

use App\Central\Models\Tenant;
use App\Modules\Academic\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Feature\Academic\AcademicTestCase;

/** A school's student limit (set by the Super Admin): new students are refused past it, nothing else is. */
class StudentLimitTest extends AcademicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsAdmin();
    }

    protected function tearDown(): void
    {
        tenancy()->tenant = null;
        parent::tearDown();
    }

    /** These tests run in one database without tenancy, so the school (and its limit) is put in place by hand. */
    private function limit(?int $max): void
    {
        tenancy()->tenant = (new Tenant)->forceFill(['id' => 'limitschool', 'is_active' => true, 'max_students' => $max]);
    }

    private function body(string $number): array
    {
        return ['student_number' => $number, 'name_ar' => 'طالب '.$number, 'name_en' => 'Student '.$number];
    }

    /** @param list<string> $numbers */
    private function file(array $numbers): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lim').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Student number', 'Name (Arabic)']));
        foreach ($numbers as $n) {
            $writer->addRow(Row::fromValues([$n, 'طالب '.$n]));
        }
        $writer->close();

        return new UploadedFile($path, 'file.xlsx', null, null, true);
    }

    private function import(array $numbers, bool $confirm = true)
    {
        return $this->post('/api/import/students', ['file' => $this->file($numbers), 'confirm' => $confirm ? '1' : '0'], ['Accept' => 'application/json']);
    }

    public function test_creating_a_student_at_the_limit_is_refused_with_the_code(): void
    {
        $this->limit(1);
        $this->postJson('/api/students', $this->body('S-1'))->assertCreated();

        $this->postJson('/api/students', $this->body('S-2'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'student_limit_reached')
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('current', 1)
            ->assertJsonPath('allowed', 0);
        $this->assertSame(1, Student::count());
    }

    public function test_null_means_unlimited(): void
    {
        $this->limit(null);
        foreach (['S-1', 'S-2', 'S-3'] as $n) {
            $this->postJson('/api/students', $this->body($n))->assertCreated();
        }
        $this->assertSame(3, Student::count());
    }

    public function test_editing_a_student_at_the_limit_still_works(): void
    {
        $this->limit(1);
        $id = $this->postJson('/api/students', $this->body('S-1'))->assertCreated()->json('data.id');

        $this->putJson("/api/students/$id", $this->body('S-1') + ['name_en' => 'Renamed'])->assertOk();
    }

    public function test_an_excel_import_that_would_pass_the_limit_is_refused_as_a_whole_and_says_how_many_are_allowed(): void
    {
        $this->limit(3);
        $this->postJson('/api/students', $this->body('S-1'))->assertCreated();

        // 2 places are left and the file has 3 new students: none of them is imported.
        $this->import(['A-1', 'A-2', 'A-3'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'student_limit_reached')
            ->assertJsonPath('allowed', 2)
            ->assertJsonPath('current', 1);
        $this->assertSame(1, Student::count());
    }

    public function test_an_import_that_fits_works(): void
    {
        $this->limit(3);
        $this->postJson('/api/students', $this->body('S-1'))->assertCreated();

        $this->import(['A-1', 'A-2'])->assertOk()->assertJsonCount(2, 'data.created');
        $this->assertSame(3, Student::count());
    }

    public function test_the_preview_of_a_file_that_does_not_fit_is_not_blocked(): void
    {
        $this->limit(1);
        $this->import(['A-1', 'A-2'], confirm: false)->assertOk()->assertJsonPath('data.valid', 2);
    }

    public function test_two_creations_when_one_place_is_left_only_one_succeeds(): void
    {
        $this->limit(2);
        $this->postJson('/api/students', $this->body('S-1'))->assertCreated();

        $this->postJson('/api/students', $this->body('S-2'))->assertCreated();
        $this->postJson('/api/students', $this->body('S-3'))->assertStatus(422)->assertJsonPath('code', 'student_limit_reached');
        $this->assertSame(2, Student::count());
    }

    public function test_the_check_uses_the_live_count_not_the_cached_one(): void
    {
        $this->limit(1);
        // The Super Admin list caches a school's counts; a stale zero there must not let a student in.
        Cache::put('school.counts.limitschool', ['students' => 0, 'staff' => 0], 300);
        $this->postJson('/api/students', $this->body('S-1'))->assertCreated();

        $this->postJson('/api/students', $this->body('S-2'))->assertStatus(422);
    }

    public function test_lowering_the_limit_below_the_count_only_blocks_new_students(): void
    {
        $this->limit(null);
        $id = $this->postJson('/api/students', $this->body('S-1'))->assertCreated()->json('data.id');
        $this->postJson('/api/students', $this->body('S-2'))->assertCreated();

        $this->limit(1); // 2 students, limit 1
        $this->postJson('/api/students', $this->body('S-3'))->assertStatus(422)->assertJsonPath('allowed', 0);
        $this->putJson("/api/students/$id", $this->body('S-1') + ['name_en' => 'Still fine'])->assertOk();
        $this->assertSame(2, Student::count());
    }
}
