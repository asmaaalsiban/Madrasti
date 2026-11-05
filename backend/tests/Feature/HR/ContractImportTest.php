<?php

namespace Tests\Feature\HR;

use App\Models\User;
use App\Modules\HR\Models\StaffContract;
use App\Shared\Enums\StaffType;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Feature\Academic\AcademicTestCase;

class ContractImportTest extends AcademicTestCase
{
    private User $sara;

    private User $omar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actAsAdmin();
        $this->sara = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara Khaled', 'email' => 'sara@school.test']);
        $this->omar = User::factory()->staff(StaffType::Teacher)->create(['name' => 'عمر ناصر', 'email' => 'omar@school.test']);
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

        return $this->post('/api/import/contracts', ['file' => new UploadedFile($path, 'contracts.xlsx', null, null, true), 'confirm' => $confirm ? '1' : '0'], ['Accept' => 'application/json']);
    }

    private const HEAD = ['Staff member', 'Base salary', 'Contract start', 'Contract end', 'Payment method', 'Monthly allowance'];

    public function test_contracts_are_created_for_staff_found_by_email_or_name(): void
    {
        $this->upload([
            self::HEAD,
            ['sara@school.test', '1,200', '2026-09-01', '2027-08-31', 'تحويل', 100],
            ['عمر ناصر', '٩٠٠', '2026-09-01', '', '', ''],
        ])->assertOk()->assertJsonPath('data.valid', 2)->assertJsonPath('data.problem_count', 0);

        $sara = StaffContract::where('user_id', $this->sara->id)->first();
        $this->assertEqualsWithDelta(1200.0, (float) $sara->base_salary, 0.001);
        $this->assertSame('bank_transfer', $sara->payment_method);
        $this->assertSame('active', $sara->status);
        $this->assertSame('allowance', $sara->items->first()->kind);
        $this->assertEqualsWithDelta(100.0, (float) $sara->items->first()->amount, 0.001);
        $this->assertEqualsWithDelta(900.0, (float) StaffContract::where('user_id', $this->omar->id)->value('base_salary'), 0.001);
        $this->assertNull(StaffContract::where('user_id', $this->omar->id)->first()->ends_on);
    }

    public function test_the_same_file_twice_never_makes_a_second_contract(): void
    {
        $rows = [self::HEAD, ['sara@school.test', 1200, '2026-09-01', '', '', '']];
        $this->upload($rows)->assertOk();
        $this->upload($rows)->assertOk()->assertJsonPath('data.valid', 0)->assertJsonPath('data.problem_count', 1);

        $this->assertSame(1, StaffContract::count());
    }

    public function test_bad_rows_are_reported_and_the_good_one_still_imports(): void
    {
        $this->upload([
            self::HEAD,
            ['nobody@school.test', 500, '2026-09-01', '', '', ''],        // unknown staff
            ['sara@school.test', 'abc', '2026-09-01', '', '', ''],        // salary
            ['sara@school.test', 500, 'soon', '', '', ''],                // date
            ['sara@school.test', 500, '2026-09-01', '2026-01-01', '', ''], // ends before it starts
            ['sara@school.test', 500, '2026-09-01', '', 'bitcoin', ''],   // method
            ['omar@school.test', 500, '2026-09-01', '', 'cash', ''],
        ])->assertOk()->assertJsonPath('data.valid', 1)->assertJsonPath('data.problem_count', 5);

        $this->assertSame(1, StaffContract::count());
    }

    public function test_a_parent_account_is_not_staff_and_non_admins_are_refused(): void
    {
        $parent = User::factory()->role(\App\Shared\Enums\Role::Guardian)->create(['email' => 'p@school.test']);

        $this->upload([self::HEAD, ['p@school.test', 500, '2026-09-01', '', '', '']], false)->assertOk()->assertJsonPath('data.problem_count', 1);
        $this->get('/api/import/contracts/template')->assertOk();

        Sanctum::actingAs(User::factory()->staff(StaffType::Accountant)->create());
        $this->post('/api/import/contracts', [], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertNotNull($parent);
    }
}
