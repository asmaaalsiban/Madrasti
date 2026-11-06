<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\MoneyAccount;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Procurement\Models\ProcurementRequest;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class PurchaseBillTest extends AcademicTestCase
{
    private User $principal;

    private User $accountant;

    private User $sara;

    private MoneyAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->principal = User::factory()->staff(StaffType::Principal)->create();
        $this->accountant = User::factory()->staff(StaffType::Accountant)->create();
        $this->sara = User::factory()->staff(StaffType::Teacher)->create();
        $this->cash = MoneyAccount::where('type', 'cash')->firstOrFail();
        Sanctum::actingAs($this->accountant);
        $this->postJson('/api/finance/money-accounts/'.$this->cash->id.'/adjustments', ['direction' => 'in', 'amount' => 1000, 'date' => '2026-11-01', 'note' => 'Opening'])->assertCreated();
    }

    private function approved(): int
    {
        Sanctum::actingAs($this->sara);
        $id = $this->postJson('/api/procurement', ['title' => 'Markers', 'quantity' => 5, 'category' => 'stationery', 'priority' => 'normal', 'estimated_cost' => 35])->assertCreated()->json('data.id');
        Sanctum::actingAs($this->principal);
        $this->postJson("/api/procurement/$id/approve", [])->assertOk();
        Sanctum::actingAs($this->accountant);

        return $id;
    }

    private function billStatus(int $billId): string
    {
        return collect($this->getJson('/api/finance/expenses')->json('data.bills') ?? $this->getJson('/api/finance/expenses')->json('data'))->firstWhere('id', $billId)['status'];
    }

    private function buy(int $id, array $extra = [])
    {
        return $this->postJson("/api/procurement/$id/purchase", ['supplier' => 'Office Plus', 'actual_cost' => 40, 'purchase_ref' => 'INV-9', ...$extra]);
    }

    public function test_the_purchase_records_an_unpaid_bill_linked_to_the_request(): void
    {
        $id = $this->approved();
        $this->buy($id)->assertOk()->assertJsonPath('data.status', 'purchased');

        $bill = Expense::where('procurement_request_id', $id)->firstOrFail();
        $this->assertSame('unpaid', $this->billStatus($bill->id));
        $this->assertSame('Supplies', $bill->category->name_en);
        $this->assertSame('Office Plus', $bill->supplier->name);

        $row = collect($this->getJson('/api/procurement')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame($bill->id, $row['expense_id']);
    }

    public function test_an_existing_supplier_is_reused_ignoring_case(): void
    {
        Supplier::create(['name' => 'OFFICE plus']);
        $this->buy($this->approved())->assertOk();
        $this->assertSame(1, Supplier::count());
    }

    public function test_pay_now_pays_the_bill_out_of_the_chosen_account(): void
    {
        $id = $this->approved();
        $this->buy($id, ['pay_now' => ['money_account_id' => $this->cash->id, 'method' => 'cash']])->assertOk();

        $this->assertSame('paid', $this->billStatus(Expense::where('procurement_request_id', $id)->value('id')));
        $this->assertSame('960.00', collect($this->getJson('/api/finance/money-accounts')->json('data'))->firstWhere('id', $this->cash->id)['balance']);
    }

    public function test_a_bad_account_rolls_everything_back(): void
    {
        $id = $this->approved();
        $this->buy($id, ['pay_now' => ['money_account_id' => 99999, 'method' => 'cash']])->assertStatus(422);

        $this->assertSame('approved', ProcurementRequest::find($id)->status);
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, Supplier::count());
    }

    public function test_the_chosen_category_and_due_date_are_used(): void
    {
        $id = $this->approved();
        $cat = ExpenseCategory::where('name_en', 'Utilities')->firstOrFail();
        $this->buy($id, ['expense_category_id' => $cat->id, 'due_date' => '2026-12-01'])->assertOk();

        $bill = Expense::where('procurement_request_id', $id)->firstOrFail();
        $this->assertSame($cat->id, $bill->expense_category_id);
        $this->assertSame('2026-12-01', $bill->due_date->toDateString());
    }

    public function test_the_principal_still_cannot_record_a_purchase(): void
    {
        $id = $this->approved();
        Sanctum::actingAs($this->principal);
        $this->buy($id)->assertForbidden();
    }
}
