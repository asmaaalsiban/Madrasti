<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\Models\AccountEntry;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\MoneyAccount;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Procurement\Models\ProcurementRequest;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class ExpensesTest extends AcademicTestCase
{
    private User $accountant;

    private MoneyAccount $cash;

    private MoneyAccount $bank;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->accountant = User::factory()->staff(StaffType::Accountant)->create();
        $this->cash = MoneyAccount::where('type', 'cash')->firstOrFail();
        $this->bank = MoneyAccount::where('type', 'bank')->firstOrFail();
        $this->category = ExpenseCategory::where('name_en', 'Utilities')->firstOrFail();
        Sanctum::actingAs($this->accountant);
        $this->postJson('/api/finance/money-accounts/'.$this->cash->id.'/adjustments', ['direction' => 'in', 'amount' => 1000, 'date' => '2026-11-01', 'note' => 'Opening'])->assertCreated();
        $this->postJson('/api/finance/money-accounts/'.$this->bank->id.'/adjustments', ['direction' => 'in', 'amount' => 5000, 'date' => '2026-11-01', 'note' => 'Opening'])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bill(array $extra = [])
    {
        return $this->postJson('/api/finance/expenses', ['expense_date' => '2026-11-05', 'expense_category_id' => $this->category->id, 'description' => 'Electricity October', 'amount' => 400, ...$extra]);
    }

    private function balance(MoneyAccount $a): string
    {
        return collect($this->getJson('/api/finance/money-accounts')->json('data'))->firstWhere('id', $a->id)['balance'];
    }

    public function test_the_school_has_default_categories(): void
    {
        $this->getJson('/api/finance/expense-categories')->assertOk()->assertJsonCount(8, 'data');
    }

    public function test_a_bill_waits_to_be_paid_and_leaves_the_books_untouched(): void
    {
        $supplier = Supplier::create(['name' => 'Power Co']);
        $this->bill(['supplier_id' => $supplier->id, 'due_date' => '2026-11-30'])->assertCreated()->assertJsonPath('data.status', 'unpaid')->assertJsonPath('data.remaining', '400.00')->assertJsonPath('data.supplier.name', 'Power Co');

        $this->assertSame('1000.00', $this->balance($this->cash)); // nothing was paid yet
        $this->getJson('/api/finance/suppliers')->assertJsonPath('data.0.owed', '400.00');
    }

    public function test_a_bill_can_be_paid_in_parts_each_out_of_an_account_with_its_own_line(): void
    {
        $id = $this->bill()->json('data.id');

        $this->postJson("/api/finance/expenses/$id/payments", ['amount' => 150, 'money_account_id' => $this->cash->id, 'method' => 'cash'])->assertCreated()->assertJsonPath('data.status', 'partial')->assertJsonPath('data.remaining', '250.00');
        $this->postJson("/api/finance/expenses/$id/payments", ['amount' => 250, 'money_account_id' => $this->bank->id, 'method' => 'bank_transfer', 'reference' => 'TRX-1'])->assertCreated()->assertJsonPath('data.status', 'paid');

        $this->assertSame('850.00', $this->balance($this->cash));   // 1000 - 150
        $this->assertSame('4750.00', $this->balance($this->bank));  // 5000 - 250
        $this->assertSame(2, AccountEntry::where('kind', 'expense')->count());
        $this->postJson("/api/finance/expenses/$id/payments", ['amount' => 1, 'money_account_id' => $this->cash->id, 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('amount'); // more than owed
    }

    public function test_a_bill_can_be_recorded_already_paid_in_one_step(): void
    {
        $this->bill(['pay_now' => ['money_account_id' => $this->cash->id, 'method' => 'cash']])->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payments.0.account.name', $this->cash->name);
        $this->assertSame('600.00', $this->balance($this->cash));
    }

    public function test_a_cancelled_payment_puts_the_money_back_unless_it_was_matched_with_the_bank(): void
    {
        $id = $this->bill()->json('data.id');
        $pay = $this->postJson("/api/finance/expenses/$id/payments", ['amount' => 400, 'money_account_id' => $this->bank->id, 'method' => 'bank_transfer'])->json('data.payments.0.id');

        $this->deleteJson("/api/finance/expense-payments/$pay")->assertOk()->assertJsonPath('data.status', 'unpaid');
        $this->assertSame('5000.00', $this->balance($this->bank));

        $pay = $this->postJson("/api/finance/expenses/$id/payments", ['amount' => 400, 'money_account_id' => $this->bank->id, 'method' => 'bank_transfer'])->json('data.payments.0.id');
        $lines = $this->getJson("/api/finance/money-accounts/{$this->bank->id}/reconciliation")->json('data.entries');
        $this->postJson("/api/finance/money-accounts/{$this->bank->id}/reconciliation", ['statement_date' => '2026-11-30', 'statement_balance' => 4600, 'entry_ids' => array_column($lines, 'id')])->assertOk();
        $this->deleteJson("/api/finance/expense-payments/$pay")->assertUnprocessable(); // the bank statement already has it
        $this->assertSame('4600.00', $this->balance($this->bank));
    }

    public function test_a_payment_never_goes_out_of_a_switched_off_or_unknown_account(): void
    {
        $id = $this->bill()->json('data.id');
        $this->bank->update(['is_active' => false]);
        $this->postJson("/api/finance/expenses/$id/payments", ['amount' => 100, 'money_account_id' => $this->bank->id, 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('money_account_id');
        $this->postJson("/api/finance/expenses/$id/payments", ['amount' => 100, 'money_account_id' => 9999, 'method' => 'cash'])->assertUnprocessable();
        $this->bill(['pay_now' => ['money_account_id' => $this->bank->id, 'method' => 'cash']])->assertUnprocessable();
        $this->assertSame(1, Expense::count()); // the failed "pay now" bill was not left behind
    }

    public function test_the_status_follows_the_payments_and_the_due_date(): void
    {
        $this->bill(['description' => 'Late', 'due_date' => '2026-11-01'])->assertCreated()->assertJsonPath('data.status', 'overdue');
        $this->bill(['description' => 'Soon', 'due_date' => '2026-12-01'])->assertCreated();
        $this->bill(['description' => 'Done', 'pay_now' => ['money_account_id' => $this->cash->id, 'method' => 'cash']])->assertCreated();

        $this->getJson('/api/finance/expenses?status=overdue')->assertJsonCount(1, 'data')->assertJsonPath('summary.overdue', '400.00');
        $this->getJson('/api/finance/expenses?status=open')->assertJsonCount(2, 'data')->assertJsonPath('summary.remaining', '800.00');
        $this->getJson('/api/finance/expenses?status=paid')->assertJsonCount(1, 'data');
        $this->getJson('/api/finance/expenses?search=Soon')->assertJsonCount(1, 'data');
    }

    public function test_the_list_has_totals_and_a_breakdown_by_category_and_filters(): void
    {
        $rent = ExpenseCategory::where('name_en', 'Rent')->first();
        $this->bill(['amount' => 400])->assertCreated();
        $this->bill(['amount' => 1500, 'expense_category_id' => $rent->id, 'description' => 'Rent', 'expense_date' => '2026-10-01'])->assertCreated();

        $all = $this->getJson('/api/finance/expenses')->assertOk()->assertJsonPath('summary.total', '1900.00')->json('summary.by_category');
        $this->assertSame('Rent', $all[0]['category']['name_en']); // the biggest first
        $this->getJson('/api/finance/expenses?from=2026-11-01')->assertJsonPath('summary.total', '400.00');
        $this->getJson('/api/finance/expenses?category_id='.$rent->id)->assertJsonCount(1, 'data');
    }

    public function test_a_bill_cannot_shrink_below_what_was_paid_and_only_an_unpaid_one_is_deleted(): void
    {
        $id = $this->bill()->json('data.id');
        $this->postJson("/api/finance/expenses/$id/payments", ['amount' => 300, 'money_account_id' => $this->cash->id, 'method' => 'cash'])->assertCreated();

        $this->putJson("/api/finance/expenses/$id", ['amount' => 200])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->putJson("/api/finance/expenses/$id", ['amount' => 450, 'description' => 'Electricity (corrected)'])->assertOk()->assertJsonPath('data.remaining', '150.00');
        $this->deleteJson("/api/finance/expenses/$id")->assertUnprocessable();

        $other = $this->bill(['description' => 'Mistake'])->json('data.id');
        $this->deleteJson("/api/finance/expenses/$other")->assertNoContent();
    }

    public function test_suppliers_and_categories_cannot_be_deleted_while_bills_use_them(): void
    {
        $supplier = $this->postJson('/api/finance/suppliers', ['name' => 'Stationery Shop', 'phone' => '0791111111'])->assertCreated()->json('data.id');
        $cat = $this->postJson('/api/finance/expense-categories', ['name_ar' => 'ضيافة', 'name_en' => 'Hospitality'])->assertCreated()->json('data.id');
        $this->bill(['supplier_id' => $supplier, 'expense_category_id' => $cat])->assertCreated();

        $this->deleteJson("/api/finance/suppliers/$supplier")->assertUnprocessable();
        $this->deleteJson("/api/finance/expense-categories/$cat")->assertUnprocessable();
        $this->putJson("/api/finance/suppliers/$supplier", ['is_active' => false])->assertOk();
        $this->putJson("/api/finance/expense-categories/$cat", ['is_active' => false])->assertOk();
        $offered = $this->getJson('/api/finance/expenses')->assertJsonCount(0, 'suppliers'); // switched off: not offered in the form (its old bill still shows it)
        $this->assertNotContains('Hospitality', array_column($offered->json('categories'), 'name_en'));

        $empty = $this->postJson('/api/finance/suppliers', ['name' => 'Nobody'])->json('data.id');
        $this->deleteJson("/api/finance/suppliers/$empty")->assertNoContent();
    }

    public function test_a_bill_can_buy_an_approved_purchase_request(): void
    {
        $request = ProcurementRequest::create(['requested_by' => User::factory()->staff(StaffType::Teacher)->create()->id, 'title' => 'Markers', 'quantity' => 20, 'category' => 'supplies', 'priority' => 'normal', 'estimated_cost' => 40, 'status' => 'approved']);

        $this->bill(['procurement_request_id' => $request->id, 'description' => 'Markers', 'amount' => 38])->assertCreated();

        $request->refresh();
        $this->assertSame('purchased', $request->status);
        $this->assertEqualsWithDelta(38.0, (float) $request->actual_cost, 0.001);
        $this->assertSame($this->accountant->id, $request->purchased_by);
    }

    public function test_the_principal_reads_but_does_not_change_and_others_see_nothing(): void
    {
        $this->bill()->assertCreated();
        $this->actAsAdmin();
        $this->getJson('/api/finance/expenses')->assertOk()->assertJsonPath('can_manage', false)->assertJsonCount(1, 'data');
        $this->getJson('/api/finance/suppliers')->assertOk();
        $this->bill()->assertForbidden();
        $this->postJson('/api/finance/suppliers', ['name' => 'X'])->assertForbidden();

        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->getJson('/api/finance/expenses')->assertForbidden();
    }

    public function test_salaries_leave_the_accounts_when_the_payroll_is_paid(): void
    {
        $principal = User::factory()->staff(StaffType::Principal)->create();
        $sara = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara']);
        $omar = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Omar']);
        $this->postJson('/api/payroll/contracts', ['user_id' => $sara->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'base_salary' => 900, 'payment_method' => 'bank_transfer'])->assertCreated();
        $this->postJson('/api/payroll/contracts', ['user_id' => $omar->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'base_salary' => 600, 'payment_method' => 'cash'])->assertCreated();
        $run = $this->postJson('/api/payroll/runs', ['month' => '2026-10'])->assertCreated()->json('data.id');
        $this->postJson("/api/payroll/runs/$run/submit")->assertOk();
        Sanctum::actingAs($principal);
        $this->postJson("/api/payroll/runs/$run/approve")->assertOk();
        Sanctum::actingAs($this->accountant);

        $this->postJson("/api/payroll/runs/$run/pay", [])->assertOk();

        $slips = \App\Modules\HR\Models\Payslip::where('payroll_run_id', $run)->get()->keyBy('user_id');
        $this->assertSame(2, AccountEntry::where('kind', 'payroll')->count());
        // Sara is paid by bank transfer (out of the bank), Omar in cash (out of the cash box).
        $this->assertSame(number_format(5000 - (float) $slips[$sara->id]->net, 2, '.', ''), $this->balance($this->bank));
        $this->assertSame(number_format(1000 - (float) $slips[$omar->id]->net, 2, '.', ''), $this->balance($this->cash));
    }
}
