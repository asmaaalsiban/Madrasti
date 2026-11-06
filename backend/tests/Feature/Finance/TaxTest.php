<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Services\FeeBilling;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class TaxTest extends AcademicTestCase
{
    private AcademicYear $year;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->student = Student::create(['student_number' => 'S-1', 'name_ar' => 'سارة', 'name_en' => 'Sara', 'gender' => 'female']);
        Sanctum::actingAs(User::factory()->staff(StaffType::Accountant)->create());
    }

    private function net(string $code): float
    {
        $id = DB::table('ledger_accounts')->where('code', $code)->value('id');

        return (float) DB::table('journal_lines')->where('ledger_account_id', $id)->selectRaw('coalesce(sum(debit),0) - coalesce(sum(credit),0) as n')->value('n');
    }

    private function turnOn(array $extra = []): void
    {
        $this->putJson('/api/finance/tax-settings', ['enabled' => true, 'name' => 'VAT', 'rate' => 16, 'fee_kinds' => ['transport'], ...$extra])->assertOk();
    }

    public function test_with_tax_off_nothing_changes(): void
    {
        $billing = app(FeeBilling::class);
        $billing->createFee($this->student, $this->year, 'Bus', '116.00', '0', $billing->evenSchedule(11600, 1, '2026-09-01'), null, 'transport');
        $this->assertSame(0.0, $this->net('2200'));
        $this->assertSame(-116.0, $this->net('4000'));
    }

    public function test_a_taxable_fee_splits_into_income_and_tax_and_the_tax_follows_a_rate_change(): void
    {
        $this->turnOn();
        $billing = app(FeeBilling::class);
        $billing->createFee($this->student, $this->year, 'Bus', '116.00', '0', $billing->evenSchedule(11600, 1, '2026-09-01'), null, 'transport');
        $billing->createFee($this->student, $this->year, 'Tuition', '1000.00', '0', $billing->evenSchedule(100000, 2, '2026-09-01'), null, 'yearly'); // not a taxable kind

        $this->assertSame(1116.0, $this->net('1200'));
        $this->assertSame(-16.0, $this->net('2200'));
        $this->assertSame(-1100.0, $this->net('4000'));

        $this->turnOn(['rate' => 0]);
        $this->assertSame(0.0, $this->net('2200'));
        $this->assertSame(-1116.0, $this->net('4000'));
    }

    public function test_a_bill_with_tax_posts_the_tax_apart_and_the_summary_nets_it(): void
    {
        $this->turnOn();
        $billing = app(FeeBilling::class);
        $billing->createFee($this->student, $this->year, 'Bus', '116.00', '0', $billing->evenSchedule(11600, 1, '2026-09-01'), null, 'transport');
        $cat = ExpenseCategory::where('name_en', 'Rent')->firstOrFail();
        $this->postJson('/api/finance/expenses', ['expense_date' => '2026-09-10', 'expense_category_id' => $cat->id, 'description' => 'Rent', 'amount' => 232, 'tax_amount' => 32])->assertCreated()->assertJsonPath('data.tax_amount', '32.00');

        $this->assertSame(32.0, $this->net('1300'));
        $this->assertSame(200.0, $this->net('5100'));
        $r = $this->getJson('/api/finance/accounting-reports/tax-summary?from=2026-01-01&to=2026-12-31')->assertOk()->json('data.totals');
        $this->assertSame(['output' => '16.00', 'input' => '32.00', 'net' => '-16.00'], $r);
    }

    public function test_a_bill_cannot_hold_more_tax_than_its_amount_and_the_principal_cannot_change_settings(): void
    {
        $cat = ExpenseCategory::where('name_en', 'Rent')->firstOrFail();
        $this->postJson('/api/finance/expenses', ['expense_date' => '2026-09-10', 'expense_category_id' => $cat->id, 'description' => 'x', 'amount' => 10, 'tax_amount' => 11])->assertStatus(422);
        Sanctum::actingAs(User::factory()->staff(StaffType::Principal)->create());
        $this->getJson('/api/finance/tax-settings')->assertOk()->assertJsonPath('can_manage', false);
        $this->putJson('/api/finance/tax-settings', ['enabled' => true, 'name' => 'VAT', 'rate' => 16])->assertForbidden();
    }
}
