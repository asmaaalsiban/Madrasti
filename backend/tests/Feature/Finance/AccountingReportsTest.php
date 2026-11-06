<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\LedgerAccount;
use App\Modules\Finance\Models\MoneyAccount;
use App\Modules\Finance\Services\FeeBilling;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class AccountingReportsTest extends AcademicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $student = Student::create(['student_number' => 'S-1', 'name_ar' => 'سارة', 'name_en' => 'Sara', 'gender' => 'female']);
        $billing = app(FeeBilling::class);
        $fee = $billing->createFee($student, $year, 'Tuition', '2000.00', '0', $billing->evenSchedule(200000, 4, '2026-09-01'), null, 'yearly');
        Sanctum::actingAs(User::factory()->staff(StaffType::Accountant)->create());
        $this->postJson('/api/finance/payments', ['student_fee_id' => $fee->id, 'amount' => '500.00', 'method' => 'cash', 'paid_on' => '2026-09-05'])->assertCreated();
        $cat = ExpenseCategory::where('name_en', 'Rent')->firstOrFail();
        $bill = $this->postJson('/api/finance/expenses', ['expense_date' => '2026-09-10', 'expense_category_id' => $cat->id, 'description' => 'Rent', 'amount' => 300])->assertCreated()->json('data.id');
        $this->postJson("/api/finance/expenses/$bill/payments", ['amount' => 100, 'money_account_id' => MoneyAccount::where('type', 'cash')->value('id'), 'method' => 'cash', 'paid_on' => '2026-09-11'])->assertCreated();
    }

    private function report(string $name, string $query = '')
    {
        return $this->getJson("/api/finance/accounting-reports/$name?to=2026-12-31&from=2026-01-01$query");
    }

    public function test_the_trial_balance_balances(): void
    {
        $r = $this->report('trial-balance')->assertOk()->json('data');
        $this->assertTrue($r['balanced']);
        $this->assertSame($r['totals']['debit'], $r['totals']['credit']);
    }

    public function test_the_income_statement_shows_what_was_earned_and_spent(): void
    {
        $r = $this->report('income-statement')->assertOk()->json('data.totals');
        $this->assertSame('2000.00', $r['income']);
        $this->assertSame('300.00', $r['expense']);
        $this->assertSame('1700.00', $r['profit']);
    }

    public function test_the_balance_sheet_balances_and_counts_the_profit_as_equity(): void
    {
        $r = $this->report('balance-sheet')->assertOk()->json('data');
        $this->assertTrue($r['balanced']);
        $this->assertSame('1700.00', $r['totals']['equity']);
        $this->assertSame('1900.00', $r['totals']['assets']); // 400 in the box + 1500 still owed by students
        $this->assertSame('200.00', $r['totals']['liabilities']); // rent still unpaid
    }

    public function test_the_account_ledger_runs_a_balance_per_line(): void
    {
        $cash = LedgerAccount::where('code', '1000')->value('id');
        $r = $this->report('account-ledger', "&account_id=$cash")->assertOk()->json('data');
        $this->assertSame('400.00', $r['totals']['closing']); // 500 in, 100 out
        $this->assertCount(2, $r['rows']);
        $this->report('account-ledger')->assertStatus(422);
    }

    public function test_receivables_ageing_splits_what_is_owed_by_lateness(): void
    {
        $r = $this->getJson('/api/finance/accounting-reports/receivables-ageing?to=2026-11-15')->assertOk()->json('data');
        $this->assertSame('1500.00', $r['totals']['total']); // 2000 - 500
        $this->assertCount(1, $r['rows']);
    }

    public function test_a_report_downloads_as_excel_and_only_finance_people_read_it(): void
    {
        $this->report('trial-balance', '&format=xlsx')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->report('nope')->assertNotFound();
        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->report('trial-balance')->assertForbidden();
    }
}
