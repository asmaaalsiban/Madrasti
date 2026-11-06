<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\MoneyAccount;
use App\Modules\Finance\Models\StudentFee;
use App\Modules\Finance\Services\FeeBilling;
use App\Modules\Finance\Services\Posting;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class AutoPostingTest extends AcademicTestCase
{
    private StudentFee $fee;

    private MoneyAccount $cash;

    private MoneyAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $student = Student::create(['student_number' => 'S-1', 'name_ar' => 'سارة', 'name_en' => 'Sara', 'gender' => 'female']);
        $billing = app(FeeBilling::class);
        $this->fee = $billing->createFee($student, $year, 'Tuition', '2000.00', '0', $billing->evenSchedule(200000, 4, '2026-09-01'), null, 'yearly');
        $this->cash = MoneyAccount::where('type', 'cash')->firstOrFail();
        $this->bank = MoneyAccount::where('type', 'bank')->firstOrFail();
        Sanctum::actingAs(User::factory()->staff(StaffType::Accountant)->create());
    }

    /** Debit minus credit of one ledger account, in dinars. */
    private function net(string $code): float
    {
        $id = DB::table('ledger_accounts')->where('code', $code)->value('id');

        return (float) DB::table('journal_lines')->where('ledger_account_id', $id)->selectRaw('coalesce(sum(debit),0) - coalesce(sum(credit),0) as n')->value('n');
    }

    private function assertBalanced(): void
    {
        $this->assertEqualsWithDelta(0.0, (float) DB::table('journal_lines')->selectRaw('sum(debit) - sum(credit) as n')->value('n'), 0.001);
    }

    public function test_a_new_fee_is_owed_and_earned(): void
    {
        $this->assertSame(2000.0, $this->net('1200'));
        $this->assertSame(-2000.0, $this->net('4000'));
        $this->assertBalanced();
    }

    public function test_a_payment_moves_money_into_the_box_and_clears_what_was_owed_and_a_void_takes_it_back(): void
    {
        $id = $this->postJson('/api/finance/payments', ['student_fee_id' => $this->fee->id, 'amount' => '300.00', 'method' => 'cash', 'paid_on' => '2026-09-05'])->assertCreated()->json('data.id');
        $this->postJson('/api/finance/payments', ['student_fee_id' => $this->fee->id, 'amount' => '200.00', 'method' => 'bank_transfer', 'paid_on' => '2026-09-06'])->assertCreated();
        $this->assertSame(300.0, $this->net('1000'));
        $this->assertSame(200.0, $this->net('1100'));
        $this->assertSame(1500.0, $this->net('1200'));

        $this->deleteJson("/api/finance/payments/$id")->assertNoContent();
        $this->assertSame(0.0, $this->net('1000'));
        $this->assertSame(1800.0, $this->net('1200'));
        $this->assertBalanced();
    }

    public function test_changing_a_discount_changes_what_is_owed(): void
    {
        $this->putJson("/api/finance/fees/{$this->fee->id}", ['discount' => '200.00'])->assertOk();
        $this->assertSame(1800.0, $this->net('1200'));
        $this->assertSame(-1800.0, $this->net('4000'));
        $this->assertBalanced();
    }

    public function test_a_bill_its_payment_and_a_transfer_post_by_themselves(): void
    {
        MoneyAccount::whereKey($this->cash->id)->update(['opening_balance' => 1000, 'opened_on' => '2026-09-01']);
        $this->cash->refresh()->save();
        $this->assertSame(1000.0, $this->net('1000'));

        $cat = ExpenseCategory::where('name_en', 'Rent')->firstOrFail();
        $bill = $this->postJson('/api/finance/expenses', ['expense_date' => '2026-09-10', 'expense_category_id' => $cat->id, 'description' => 'September rent', 'amount' => 400])->assertCreated()->json('data.id');
        $this->assertSame(400.0, $this->net('5100'));
        $this->assertSame(-400.0, $this->net('2000'));

        $this->postJson("/api/finance/expenses/$bill/payments", ['amount' => 400, 'money_account_id' => $this->cash->id, 'method' => 'cash', 'paid_on' => '2026-09-11'])->assertCreated();
        $this->assertSame(0.0, $this->net('2000'));
        $this->assertSame(600.0, $this->net('1000'));

        $this->postJson('/api/finance/money-transfers', ['from_id' => $this->cash->id, 'to_id' => $this->bank->id, 'amount' => 100, 'date' => '2026-09-12'])->assertCreated();
        $this->assertSame(500.0, $this->net('1000'));
        $this->assertSame(100.0, $this->net('1100'));
        $this->assertSame(0.0, $this->net('1190'));
        $this->assertBalanced();
    }

    public function test_the_cash_ledger_always_equals_the_money_accounts_and_rebuilding_changes_nothing(): void
    {
        $this->postJson('/api/finance/payments', ['student_fee_id' => $this->fee->id, 'amount' => '300.00', 'method' => 'cash', 'paid_on' => '2026-09-05'])->assertCreated();
        $before = DB::table('journal_entries')->count();
        app(Posting::class)->rebuildAll();
        $this->assertSame($before, DB::table('journal_entries')->count());
        $this->assertEqualsWithDelta($this->net('1000') + $this->net('1100'), (float) $this->getJson('/api/finance/money-accounts')->json('total'), 0.001);
    }

    public function test_a_locked_day_is_never_touched_the_change_lands_on_the_first_open_day(): void
    {
        $this->postJson('/api/finance/payments', ['student_fee_id' => $this->fee->id, 'amount' => '300.00', 'method' => 'cash', 'paid_on' => '2026-09-05'])->assertCreated();
        $this->putJson('/api/finance/books-lock', ['locked_until' => '2026-09-30'])->assertOk();
        $entry = DB::table('journal_entries')->count();

        $this->postJson('/api/finance/payments', ['student_fee_id' => $this->fee->id, 'amount' => '100.00', 'method' => 'cash', 'paid_on' => '2026-09-20'])->assertCreated();
        $this->assertGreaterThan($entry, DB::table('journal_entries')->count());
        $this->assertSame('2026-10-01', DB::table('journal_entries')->orderByDesc('id')->value('entry_date'));
        $this->assertBalanced();
    }
}
