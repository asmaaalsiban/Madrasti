<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class BudgetTest extends AcademicTestCase
{
    private AcademicYear $year;

    private ExpenseCategory $rent;

    private ExpenseCategory $supplies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = AcademicYear::factory()->create(['is_current' => true, 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->rent = ExpenseCategory::where('name_en', 'Rent')->firstOrFail();
        $this->supplies = ExpenseCategory::where('name_en', 'Supplies')->firstOrFail();
        Sanctum::actingAs(User::factory()->staff(StaffType::Accountant)->create());
    }

    private function bill(ExpenseCategory $c, string $amount, string $date = '2026-10-01'): void
    {
        $this->postJson('/api/finance/expenses', ['expense_date' => $date, 'expense_category_id' => $c->id, 'description' => 'x', 'amount' => $amount])->assertCreated();
    }

    private function row(ExpenseCategory $c): array
    {
        return collect($this->getJson('/api/finance/budgets')->assertOk()->json('data.rows'))->firstWhere('category_id', $c->id);
    }

    public function test_a_budget_is_saved_per_category_and_compared_with_the_bills_of_the_year(): void
    {
        $this->putJson('/api/finance/budgets', ['academic_year_id' => $this->year->id, 'items' => [['category_id' => $this->rent->id, 'amount' => 1000], ['category_id' => $this->supplies->id, 'amount' => 200]]])->assertOk();
        $this->bill($this->rent, '600');
        $this->bill($this->supplies, '250');
        $this->bill($this->rent, '999', '2025-01-01'); // another year: not counted

        $rent = $this->row($this->rent);
        $this->assertSame('600.00', $rent['spent']);
        $this->assertSame('400.00', $rent['remaining']);
        $this->assertSame(60, $rent['percent']);
        $this->assertSame('ok', $rent['status']);
        $this->assertSame('over', $this->row($this->supplies)['status']);
        $this->assertSame('1200.00', $this->getJson('/api/finance/budgets')->json('data.totals.budget'));
    }

    public function test_saving_again_updates_instead_of_duplicating(): void
    {
        foreach ([500, 800] as $amount) {
            $this->putJson('/api/finance/budgets', ['academic_year_id' => $this->year->id, 'items' => [['category_id' => $this->rent->id, 'amount' => $amount]]])->assertOk();
        }
        $this->assertSame('800.00', $this->row($this->rent)['budget']);
        $this->assertSame(1, \App\Modules\Finance\Models\Budget::count());
    }

    public function test_spending_without_a_budget_is_flagged_and_the_principal_cannot_change_it(): void
    {
        $this->bill($this->rent, '50');
        $this->assertSame('unplanned', $this->row($this->rent)['status']);

        Sanctum::actingAs(User::factory()->staff(StaffType::Principal)->create());
        $this->getJson('/api/finance/budgets')->assertOk()->assertJsonPath('can_manage', false);
        $this->putJson('/api/finance/budgets', ['academic_year_id' => $this->year->id, 'items' => [['category_id' => $this->rent->id, 'amount' => 1]]])->assertForbidden();
    }
}
