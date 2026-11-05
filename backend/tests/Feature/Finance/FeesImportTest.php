<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\StudentFee;
use App\Shared\Enums\StaffType;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Feature\Academic\AcademicTestCase;

class FeesImportTest extends AcademicTestCase
{
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsAdmin();
        $this->year = AcademicYear::factory()->create(['is_current' => true, 'name' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        foreach ([1, 2] as $n) {
            Student::create(['student_number' => "S-$n", 'name_ar' => "ط$n", 'name_en' => "S$n", 'gender' => 'male']);
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

        return $this->post('/api/import/fees', ['file' => new UploadedFile($path, 'fees.xlsx', null, null, true), 'confirm' => $confirm ? '1' : '0'], ['Accept' => 'application/json']);
    }

    private const HEAD = ['Student number', 'Total fee', 'Discount', 'Installments', 'First due date', 'Paid so far', 'Payment method'];

    public function test_a_fee_is_split_into_installments_and_what_was_paid_is_recorded(): void
    {
        $this->upload([self::HEAD, ['S-1', 1200, 200, 4, '2026-09-15', 500, 'تحويل']])->assertOk()->assertJsonPath('data.problem_count', 0);

        $fee = StudentFee::first();
        $this->assertEqualsWithDelta(1000.0, (float) $fee->total_amount - (float) $fee->discount, 0.001);
        $this->assertSame(4, $fee->installments()->count());
        $this->assertSame('2026-09-15', $fee->installments()->orderBy('number')->first()->due_date->toDateString());
        $payment = Payment::first();
        $this->assertSame('bank_transfer', $payment->method);
        $this->assertEqualsWithDelta(500.0, (float) $payment->amount, 0.001);
        $this->assertEqualsWithDelta(500.0, Installment::sum('paid_amount'), 0.001);
    }

    public function test_the_same_file_twice_never_charges_a_student_twice(): void
    {
        $rows = [self::HEAD, ['S-1', 800, 0, 2, '2026-09-15', 0, '']];
        $this->upload($rows)->assertOk()->assertJsonPath('data.problem_count', 0);
        $this->upload($rows)->assertOk()->assertJsonPath('data.valid', 0)->assertJsonPath('data.problem_count', 1);

        $this->assertSame(1, StudentFee::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_bad_rows_are_reported_and_good_rows_still_import(): void
    {
        $response = $this->upload([
            self::HEAD,
            ['NOPE', 500, 0, 1, '', 0, ''],              // unknown student
            ['S-1', 'abc', 0, 1, '', 0, ''],             // not an amount
            ['S-1', 500, 600, 1, '', 0, ''],             // discount above the fee
            ['S-1', 500, 0, 40, '', 0, ''],              // too many installments
            ['S-1', 500, 0, 2, '', 700, ''],             // paid more than owed
            ['S-1', 500, 0, 2, '', 100, 'bitcoin'],      // unknown method
            ['S-2', 500, 0, 2, '2026-09-01', 100, 'cash'],
        ])->assertOk();

        $response->assertJsonPath('data.valid', 1)->assertJsonPath('data.problem_count', 6);
        $this->assertSame(1, StudentFee::count());
        $this->assertSame('S-2', StudentFee::first()->student->student_number);
    }

    public function test_the_preview_creates_nothing_the_template_downloads_and_non_admins_are_refused(): void
    {
        $this->upload([self::HEAD, ['S-1', 500, 0, 1, '', 0, '']], false)->assertOk()->assertJsonPath('data.valid', 1);
        $this->assertSame(0, StudentFee::count());
        $this->get('/api/import/fees/template')->assertOk();

        Sanctum::actingAs(User::factory()->staff(StaffType::Accountant)->create());
        $this->post('/api/import/fees', [], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_thousands_separators_and_arabic_digits_are_read(): void
    {
        $this->upload([self::HEAD, ['S-1', '1,200', '١٠٠', '٤', '2026-09-15', '٣٠٠', '']])->assertOk()->assertJsonPath('data.problem_count', 0);

        $fee = StudentFee::first();
        $this->assertEqualsWithDelta(1200.0, (float) $fee->total_amount, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $fee->discount, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) Payment::first()->amount, 0.001);
    }
}
