<?php

namespace Tests\Feature\Handover;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Services\FeeBilling;
use App\Shared\Enums\Role;
use App\Shared\Export\XlsxExport;
use App\Shared\Handover\HandoverPackage;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TenantTestCase;
use ZipArchive;

class HandoverPackageTest extends TenantTestCase
{
    private const SECTIONS = ['accounting', 'people', 'payroll', 'academic', 'uploaded_files'];

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            is_dir($file) ? $this->rmdir($file) : @unlink($file);
        }
        parent::tearDown();
    }

    private function rmdir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $f) {
            is_dir($f) ? $this->rmdir($f) : unlink($f);
        }
        rmdir($dir);
    }

    private function build(): ZipArchive
    {
        $path = app(HandoverPackage::class)->build();
        $this->cleanup[] = $path;
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        return $zip;
    }

    /** @return list<list<mixed>> */
    private function sheet(ZipArchive $zip, string $entry): array
    {
        $file = tempnam(sys_get_temp_dir(), 'hx');
        $this->cleanup[] = $file;
        file_put_contents($file, $zip->getFromName($entry));

        return $this->read($file);
    }

    /** @return list<list<mixed>> */
    private function read(string $file): array
    {
        $reader = new Reader();
        $reader->open($file);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }

    public function test_the_package_has_a_folder_per_section_and_a_readme(): void
    {
        $zip = $this->build();
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        foreach (self::SECTIONS as $section) {
            $this->assertContains($section.'/', $names);
        }
        $readme = (string) $zip->getFromName('README.txt');
        $this->assertStringContainsString(now()->toDateString(), $readme);
        $this->assertStringContainsString('installments.xlsx', $readme);
        $this->assertStringContainsString('الأقساط', $readme);
        $this->assertStringContainsString('Installments', $readme);
        $this->assertLessThan(array_search('people/', $names), array_search('accounting/', $names));
    }

    public function test_the_accounting_files_hold_a_students_installments_and_a_payment(): void
    {
        $year = AcademicYear::factory()->create(['name' => '2026-2027', 'is_current' => true, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $room = Classroom::factory()->create(['name' => '5-A', 'academic_year_id' => $year->id]);
        $ali = Student::create(['student_number' => 'S-1', 'name_ar' => 'علي', 'name_en' => 'Ali']);
        Enrollment::create(['student_id' => $ali->id, 'classroom_id' => $room->id, 'academic_year_id' => $year->id]);
        $admin = User::factory()->role(Role::Admin)->create();
        $billing = app(FeeBilling::class);
        $fee = $billing->createFee($ali, $year, 'Tuition', 700, 0, $billing->evenSchedule(70000, 4, '2026-09-01'));
        $payment = $billing->recordPayment($fee, 175, 'cash', '2026-09-02', $admin, null, null, null, 'Abu Ali');

        $zip = $this->build();

        $installments = $this->sheet($zip, 'accounting/installments.xlsx');
        $this->assertCount(5, $installments); // header + 4
        $this->assertEquals(['S-1', 'علي', 'Ali', '2026-2027', 'Tuition', 1, '2026-09-01', 175, 175, 'paid'], $installments[1]);
        $this->assertEquals(['S-1', 'علي', 'Ali', '2026-2027', 'Tuition', 2, '2026-10-01', 175, 0, 'pending'], $installments[2]);

        $payments = $this->sheet($zip, 'accounting/payments.xlsx');
        $this->assertSame($payment->receiptNumber(), $payments[1][0]);
        $this->assertContains('Abu Ali', $payments[1]);
        $this->assertContains('2026-09-02', $payments[1]);

        $fees = $this->sheet($zip, 'accounting/student_fees.xlsx');
        $this->assertEquals(700, $fees[1][6]);

        $journal = $this->sheet($zip, 'accounting/journal.xlsx');
        $this->assertGreaterThan(1, count($journal));
        $this->assertNotEmpty($zip->getFromName('accounting/trial_balance_2026-2027.xlsx'));
        $this->assertNotEmpty($zip->getFromName('accounting/tax_summary_2026-2027.xlsx'));
        $this->assertNotEmpty($zip->getFromName('people/students.xlsx'));
    }

    public function test_a_school_with_no_data_still_gives_a_valid_package_with_headers_only(): void
    {
        $zip = $this->build();
        $sheets = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! str_ends_with($name, '.xlsx')) {
                continue;
            }
            $sheets++;
            $rows = $this->sheet($zip, $name);
            $this->assertNotEmpty($rows, "$name has no header row");
            // the migrations seed a chart of accounts and two money accounts; everything else is empty
            if (! in_array($name, ['accounting/ledger_accounts.xlsx', 'accounting/money_accounts.xlsx'], true)) {
                $this->assertLessThanOrEqual(2, count($rows), "$name should hold headers only");
            }
        }
        $this->assertGreaterThan(15, $sheets);
        $installments = $this->sheet($zip, 'accounting/installments.xlsx');
        $this->assertCount(1, $installments);
        $this->assertContains('Due date', array_map(fn ($h) => explode(' / ', (string) $h)[1] ?? '', $installments[0]));
    }

    public function test_a_year_without_any_entries_still_gets_its_four_reports(): void
    {
        AcademicYear::factory()->create(['name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);

        $zip = $this->build();

        foreach (['trial_balance', 'income_statement', 'balance_sheet', 'tax_summary'] as $report) {
            $this->assertNotEmpty($this->sheet($zip, "accounting/{$report}_2026-2027.xlsx"), $report);
        }
        $this->assertStringContainsString('trial_balance_2026-2027.xlsx', (string) $zip->getFromName('README.txt'));
    }

    public function test_uploaded_files_are_inside(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config(['backup.file_disks' => ['local', 'public']]);
        Storage::disk('local')->put('homework/work.txt', 'private file');
        Storage::disk('public')->put('avatars/a.txt', 'public file');

        $zip = $this->build();

        $this->assertSame('private file', $zip->getFromName('uploaded_files/local/homework/work.txt'));
        $this->assertSame('public file', $zip->getFromName('uploaded_files/public/avatars/a.txt'));
        $this->assertTrue(Storage::disk('local')->exists('homework/work.txt'));
    }

    /** @return list<string> */
    private function leftovers(): array
    {
        return array_merge(glob(sys_get_temp_dir().'/handover_*') ?: [], glob(sys_get_temp_dir().'/hov*') ?: []);
    }

    public function test_a_file_that_cannot_be_read_fails_the_build_and_leaves_nothing_behind(): void
    {
        config(['backup.file_disks' => ['local']]);
        $disk = \Mockery::mock();
        $disk->shouldReceive('allFiles')->andReturn(['docs/gone.txt']);
        $disk->shouldReceive('readStream')->with('docs/gone.txt')->andReturn(null);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $before = $this->leftovers();

        try {
            app(HandoverPackage::class)->build();
            $this->fail('The build should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('local', $e->getMessage());
            $this->assertStringContainsString('docs/gone.txt', $e->getMessage());
        }
        $this->assertSame($before, $this->leftovers());
    }

    public function test_a_path_that_would_leave_the_package_folder_fails_the_build(): void
    {
        config(['backup.file_disks' => ['local']]);
        $disk = \Mockery::mock();
        $disk->shouldReceive('allFiles')->andReturn(['../evil.txt']);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('../evil.txt');
        app(HandoverPackage::class)->build();
    }

    public function test_xlsx_write_makes_a_file_and_download_still_works(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xw');
        $this->cleanup[] = $path;
        app(XlsxExport::class)->write($path, 'Names', ['A', 'B'], (function () {
            yield ['x', 1];
            yield ['y', 2];
        })());
        $this->assertSame([['A', 'B'], ['x', 1], ['y', 2]], $this->read($path));

        $response = app(XlsxExport::class)->download('My file', 'Names', ['A'], [['z']]);
        $this->assertSame([['A'], ['z']], $this->read($response->getFile()->getPathname()));
        $this->assertStringContainsString('My_file.xlsx', (string) $response->headers->get('Content-Disposition'));
    }
}
