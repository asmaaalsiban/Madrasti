<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\AccountEntry;
use App\Modules\Finance\Models\MoneyAccount;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\StudentFee;
use App\Modules\Finance\Services\FeeBilling;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class MoneyAccountsTest extends AcademicTestCase
{
    private User $accountant;

    private StudentFee $fee;

    private MoneyAccount $cash;

    private MoneyAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $year = AcademicYear::factory()->create(['is_current' => true, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $student = Student::create(['student_number' => 'S-1', 'name_ar' => 'سارة', 'name_en' => 'Sara', 'gender' => 'female']);
        $this->accountant = User::factory()->staff(StaffType::Accountant)->create();
        $billing = app(FeeBilling::class);
        $this->fee = $billing->createFee($student, $year, 'Tuition', '2000.00', '0', $billing->evenSchedule(200000, 4, '2026-09-01'), null, 'yearly');
        $this->cash = MoneyAccount::where('type', 'cash')->firstOrFail();
        $this->bank = MoneyAccount::where('type', 'bank')->firstOrFail();
        Sanctum::actingAs($this->accountant);
    }

    private function pay(string $amount, string $method = 'cash', array $extra = [])
    {
        return $this->postJson('/api/finance/payments', ['student_fee_id' => $this->fee->id, 'amount' => $amount, 'method' => $method, 'paid_on' => '2026-09-05', ...$extra]);
    }

    private function balance(MoneyAccount $a): string
    {
        return collect($this->getJson('/api/finance/money-accounts')->json('data'))->firstWhere('id', $a->id)['balance'];
    }

    public function test_a_school_starts_with_a_cash_box_and_a_bank_account(): void
    {
        $this->getJson('/api/finance/money-accounts')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', '0.00')->assertJsonPath('can_manage', true);
    }

    public function test_every_payment_lands_in_the_right_account_and_the_balances_follow(): void
    {
        $this->pay('300.00', 'cash')->assertCreated();
        $this->pay('200.00', 'cheque')->assertCreated();
        $this->pay('150.00', 'bank_transfer')->assertCreated();
        $this->pay('50.00', 'online')->assertCreated();

        $this->assertSame('500.00', $this->balance($this->cash)); // cash and cheques
        $this->assertSame('200.00', $this->balance($this->bank)); // transfers and online
        $this->assertSame($this->cash->id, Payment::where('method', 'cash')->first()->money_account_id);
        $this->getJson('/api/finance/money-accounts')->assertJsonPath('total', '700.00');
    }

    public function test_a_payment_can_be_sent_to_a_chosen_account_but_never_to_a_switched_off_one(): void
    {
        $second = MoneyAccount::create(['name' => 'Second bank', 'type' => 'bank', 'opened_on' => '2026-09-01']);
        $this->pay('100.00', 'bank_transfer', ['money_account_id' => $second->id])->assertCreated();
        $this->assertSame('100.00', $this->balance($second));
        $this->assertSame('0.00', $this->balance($this->bank));

        $second->update(['is_active' => false]);
        $this->pay('100.00', 'cash', ['money_account_id' => $second->id])->assertUnprocessable()->assertJsonValidationErrors('money_account_id');
        $this->assertSame(1, Payment::count()); // nothing was half recorded
    }

    public function test_a_cancelled_payment_leaves_the_book_unless_it_was_matched_with_the_bank(): void
    {
        $id = $this->pay('300.00', 'bank_transfer')->json('data.id');
        $this->deleteJson("/api/finance/payments/$id")->assertNoContent();
        $this->assertSame('0.00', $this->balance($this->bank));
        $this->assertSame(0, AccountEntry::count());

        $id = $this->pay('300.00', 'bank_transfer')->json('data.id');
        $lines = $this->getJson("/api/finance/money-accounts/{$this->bank->id}/reconciliation")->json('data.entries');
        $this->postJson("/api/finance/money-accounts/{$this->bank->id}/reconciliation", ['statement_date' => '2026-09-30', 'statement_balance' => 300, 'entry_ids' => array_column($lines, 'id')])->assertOk();
        $this->deleteJson("/api/finance/payments/$id")->assertUnprocessable();
        $this->assertSame('300.00', $this->balance($this->bank));
    }

    public function test_money_moves_between_accounts_and_the_total_stays(): void
    {
        $this->pay('800.00', 'cash')->assertCreated();

        $this->postJson('/api/finance/money-transfers', ['from_id' => $this->cash->id, 'to_id' => $this->bank->id, 'amount' => 500, 'date' => '2026-09-06'])->assertCreated();

        $this->assertSame('300.00', $this->balance($this->cash));
        $this->assertSame('500.00', $this->balance($this->bank));
        $this->getJson('/api/finance/money-accounts')->assertJsonPath('total', '800.00');
        $this->assertSame(2, AccountEntry::whereNotNull('transfer_ref')->count());
        $this->postJson('/api/finance/money-transfers', ['from_id' => $this->cash->id, 'to_id' => $this->cash->id, 'amount' => 5, 'date' => '2026-09-06'])->assertUnprocessable();
        $this->postJson('/api/finance/money-transfers', ['from_id' => $this->cash->id, 'to_id' => $this->bank->id, 'amount' => 0, 'date' => '2026-09-06'])->assertUnprocessable();
    }

    public function test_a_correction_needs_a_reason_and_can_go_either_way(): void
    {
        $url = "/api/finance/money-accounts/{$this->cash->id}/adjustments";
        $this->postJson($url, ['direction' => 'out', 'amount' => 25, 'date' => '2026-09-07'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson($url, ['direction' => 'in', 'amount' => 100, 'date' => '2026-09-07', 'note' => 'Cash count: found'])->assertCreated();
        $this->postJson($url, ['direction' => 'out', 'amount' => 25, 'date' => '2026-09-08', 'note' => 'Bank fee'])->assertCreated();
        $this->assertSame('75.00', $this->balance($this->cash));
    }

    public function test_the_ledger_shows_the_running_balance_from_the_opening_balance_and_the_date_filter_keeps_the_balance_forward(): void
    {
        $this->putJson("/api/finance/money-accounts/{$this->cash->id}", ['opening_balance' => 1000])->assertOk();
        $this->pay('300.00', 'cash', ['paid_on' => '2026-09-05'])->assertCreated();
        $this->pay('200.00', 'cash', ['paid_on' => '2026-09-20'])->assertCreated();

        $all = $this->getJson("/api/finance/money-accounts/{$this->cash->id}/ledger")->assertOk()->json('data');
        $this->assertSame('1000.00', $all['forward']);
        $this->assertSame(['1300.00', '1500.00'], array_column($all['rows'], 'balance'));
        $this->assertSame('1500.00', $all['closing']);

        $late = $this->getJson("/api/finance/money-accounts/{$this->cash->id}/ledger?from=2026-09-10")->json('data');
        $this->assertSame('1300.00', $late['forward']); // what came before
        $this->assertCount(1, $late['rows']);
        $this->assertSame('1500.00', $late['closing']);
    }

    public function test_the_bank_statement_is_matched_only_when_the_balances_agree(): void
    {
        $this->pay('300.00', 'bank_transfer')->assertCreated();
        $this->pay('200.00', 'bank_transfer', ['paid_on' => '2026-09-25'])->assertCreated();
        $url = "/api/finance/money-accounts/{$this->bank->id}/reconciliation";
        $open = $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.entries')->assertJsonPath('data.cleared_balance', '0.00')->json('data.entries');

        // The statement shows only the first deposit (300): matching both lines would not agree.
        $this->postJson($url, ['statement_date' => '2026-09-30', 'statement_balance' => 300, 'entry_ids' => array_column($open, 'id')])->assertUnprocessable()->assertJsonValidationErrors('statement_balance');
        $this->postJson($url, ['statement_date' => '2026-09-30', 'statement_balance' => 300, 'entry_ids' => [$open[0]['id']]])->assertOk()
            ->assertJsonCount(1, 'data.entries')->assertJsonPath('data.cleared_balance', '300.00')->assertJsonPath('data.last.statement_balance', '300.00');
        $this->assertNotNull(AccountEntry::find($open[0]['id'])->reconciled_at);
        $this->assertNull(AccountEntry::find($open[1]['id'])->reconciled_at);

        // A line that is not open (already matched), or of another account, is refused.
        $this->postJson($url, ['statement_date' => '2026-10-31', 'statement_balance' => 500, 'entry_ids' => [$open[0]['id'], $open[1]['id']]])->assertUnprocessable()->assertJsonValidationErrors('entry_ids');
        $this->postJson($url, ['statement_date' => '2026-10-31', 'statement_balance' => 500, 'entry_ids' => [$open[1]['id']]])->assertOk()->assertJsonCount(0, 'data.entries');

        $this->putJson("/api/finance/money-accounts/{$this->bank->id}", ['opening_balance' => 50])->assertUnprocessable(); // locked once matched
    }

    public function test_accounts_can_be_added_switched_off_and_only_an_empty_one_deleted(): void
    {
        $id = $this->postJson('/api/finance/money-accounts', ['name' => 'Petty cash', 'type' => 'cash', 'opening_balance' => 40])->assertCreated()->assertJsonPath('data.balance', '40.00')->json('data.id');
        $this->putJson("/api/finance/money-accounts/$id", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/api/finance/money-accounts', ['name' => 'X', 'type' => 'purse'])->assertUnprocessable();
        $this->deleteJson("/api/finance/money-accounts/$id")->assertNoContent();

        $this->pay('10.00', 'cash')->assertCreated();
        $this->deleteJson("/api/finance/money-accounts/{$this->cash->id}")->assertUnprocessable(); // it has a book
    }

    public function test_carried_forward_balances_never_enter_an_account(): void
    {
        $this->pay('400.00', 'cash')->assertCreated();
        $before = AccountEntry::count();
        $new = AcademicYear::factory()->create(['starts_on' => '2027-09-01', 'ends_on' => '2028-06-30', 'is_current' => false]);

        $this->postJson('/api/finance/year-end', ['from_year_id' => $this->fee->academic_year_id, 'to_year_id' => $new->id, 'first_due' => '2027-09-15', 'installments' => 1])->assertOk();

        $this->assertSame($before, AccountEntry::count()); // a carry-forward is not money
        $this->assertSame('400.00', $this->balance($this->cash));
    }

    public function test_payments_taken_before_the_accounts_existed_are_moved_in_by_the_migration(): void
    {
        // Two old payments with no account, as a school that used the system before this feature has.
        foreach ([['cash', '100.00'], ['bank_transfer', '60.00']] as [$method, $amount]) {
            \Illuminate\Support\Facades\DB::table('payments')->insert(['student_fee_id' => $this->fee->id, 'amount' => $amount, 'method' => $method, 'paid_on' => '2026-08-01', 'received_by' => $this->accountant->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $migration = require database_path('migrations/tenant/2026_12_28_000073_create_money_accounts.php');
        $migration->up();
        $migration->up(); // safe to run twice

        $this->assertSame('100.00', $this->balance($this->cash));
        $this->assertSame('60.00', $this->balance($this->bank));
        $this->assertSame(2, AccountEntry::count());
    }

    public function test_the_principal_reads_but_does_not_change_and_others_see_nothing(): void
    {
        $this->actAsAdmin();
        $this->getJson('/api/finance/money-accounts')->assertOk()->assertJsonPath('can_manage', false);
        $this->getJson("/api/finance/money-accounts/{$this->cash->id}/ledger")->assertOk();
        $this->postJson('/api/finance/money-accounts', ['name' => 'X', 'type' => 'cash'])->assertForbidden();
        $this->postJson('/api/finance/money-transfers', ['from_id' => $this->cash->id, 'to_id' => $this->bank->id, 'amount' => 1, 'date' => '2026-09-06'])->assertForbidden();

        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->getJson('/api/finance/money-accounts')->assertForbidden();
    }
}
