<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\LedgerAccount;
use App\Shared\Enums\StaffType;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class JournalTest extends AcademicTestCase
{
    private User $accountant;

    private int $cash;

    private int $income;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountant = User::factory()->staff(StaffType::Accountant)->create();
        $this->cash = LedgerAccount::where('code', '1000')->value('id');
        $this->income = LedgerAccount::where('code', '4100')->value('id');
        Sanctum::actingAs($this->accountant);
    }

    private function entry(array $extra = [])
    {
        return $this->postJson('/api/finance/journal', ['entry_date' => '2026-11-05', 'memo' => 'Donation', 'lines' => [
            ['ledger_account_id' => $this->cash, 'debit' => 100], ['ledger_account_id' => $this->income, 'credit' => 100],
        ], ...$extra]);
    }

    public function test_the_school_starts_with_a_chart_of_accounts(): void
    {
        $this->getJson('/api/finance/ledger-accounts')->assertOk()->assertJsonPath('data.0.code', '1000')->assertJsonPath('can_manage', true);
    }

    public function test_a_balanced_entry_is_saved_and_listed(): void
    {
        $this->entry()->assertCreated();
        $this->getJson('/api/finance/journal')->assertOk()->assertJsonPath('data.0.memo', 'Donation')->assertJsonPath('data.0.total', '100.00')->assertJsonCount(2, 'data.0.lines');
    }

    public function test_an_unbalanced_or_one_sided_entry_is_refused(): void
    {
        $this->entry(['lines' => [['ledger_account_id' => $this->cash, 'debit' => 100], ['ledger_account_id' => $this->income, 'credit' => 90]]])->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->entry(['lines' => [['ledger_account_id' => $this->cash, 'debit' => 100, 'credit' => 100], ['ledger_account_id' => $this->income, 'credit' => 0]]])->assertStatus(422);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_a_locked_period_refuses_new_and_removed_entries(): void
    {
        $id = $this->entry()->json('data.id');
        $this->putJson('/api/finance/books-lock', ['locked_until' => '2026-11-30'])->assertOk()->assertJsonPath('locked_until', '2026-11-30');

        $this->entry()->assertStatus(422)->assertJsonValidationErrors('entry_date');
        $this->deleteJson("/api/finance/journal/$id")->assertStatus(422);
        $this->entry(['entry_date' => '2026-12-01'])->assertCreated();

        $this->putJson('/api/finance/books-lock', ['locked_until' => null])->assertOk();
        $this->deleteJson("/api/finance/journal/$id")->assertNoContent();
    }

    public function test_accounts_can_be_added_but_a_used_one_keeps_its_type_and_cannot_be_deleted(): void
    {
        $new = $this->postJson('/api/finance/ledger-accounts', ['code' => '5600', 'name_ar' => 'ضيافة', 'name_en' => 'Hospitality', 'type' => 'expense'])->assertCreated()->json('data.id');
        $this->postJson('/api/finance/ledger-accounts', ['code' => '5600', 'name_ar' => 'x', 'name_en' => 'x', 'type' => 'expense'])->assertStatus(422);
        $this->deleteJson("/api/finance/ledger-accounts/$new")->assertNoContent();
        $this->deleteJson("/api/finance/ledger-accounts/{$this->cash}")->assertStatus(422);
    }

    public function test_the_principal_reads_but_cannot_change_and_a_teacher_sees_nothing(): void
    {
        Sanctum::actingAs(User::factory()->staff(StaffType::Principal)->create());
        $this->getJson('/api/finance/journal')->assertOk()->assertJsonPath('can_manage', false);
        $this->entry()->assertForbidden();

        Sanctum::actingAs(User::factory()->staff(StaffType::Teacher)->create());
        $this->getJson('/api/finance/journal')->assertForbidden();
    }
}
