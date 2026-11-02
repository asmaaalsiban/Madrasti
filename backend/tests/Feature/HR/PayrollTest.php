<?php

namespace Tests\Feature\HR;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Services\PayrollService;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class PayrollTest extends AcademicTestCase
{
    private User $accountant;

    private User $principal;

    private User $sara;

    private User $omar;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->accountant = User::factory()->staff(StaffType::Accountant)->create(['name' => 'Accountant']);
        $this->principal = User::factory()->staff(StaffType::Principal)->create(['name' => 'Principal']);
        $this->sara = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara']);
        $this->omar = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Omar']);
        Sanctum::actingAs($this->accountant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contract(User $user, array $extra = [])
    {
        return $this->postJson('/api/payroll/contracts', ['user_id' => $user->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'base_salary' => 900, ...$extra]);
    }

    private function notice(User $user, string $kind): ?array
    {
        $row = DB::table('notifications')->where('notifiable_id', $user->id)->where('data', 'like', "%$kind%")->latest('created_at')->value('data');

        return $row ? json_decode($row, true) : null;
    }

    // ---- contracts -------------------------------------------------------------------------------------------------

    public function test_each_person_is_paid_the_way_their_payslip_says(): void
    {
        $this->contract($this->sara, ['payment_method' => 'bank_transfer', 'payment_details' => 'JO94CBJO0010000000000131000302'])->assertCreated();
        $this->contract($this->omar, ['payment_method' => 'cheque'])->assertCreated();
        $runId = $this->postJson('/api/payroll/runs', ['month' => '2026-10'])->assertCreated()->json('data.id');
        $slips = collect($this->getJson("/api/payroll/runs/$runId")->json('data.payslips'))->keyBy(fn ($s) => $s['user']['name']);
        $this->assertSame('bank_transfer', $slips['Sara']['payment_method']);
        $this->assertSame('cheque', $slips['Omar']['payment_method']);
        // The accountant can change one person for this month only.
        $this->putJson('/api/payroll/payslips/'.$slips['Omar']['id'].'/payment', ['payment_method' => 'cash'])->assertOk()->assertJsonPath('data.payment_method', 'cash');
        $this->postJson("/api/payroll/runs/$runId/submit")->assertOk();
        Sanctum::actingAs($this->principal);
        $this->postJson("/api/payroll/runs/$runId/approve")->assertOk();
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/payroll/runs/$runId/pay", [])->assertOk();
        $paid = Payslip::where('payroll_run_id', $runId)->get()->keyBy('user_id');
        $this->assertSame('bank_transfer', $paid[$this->sara->id]->payment_method);
        $this->assertSame('cash', $paid[$this->omar->id]->payment_method);
        // A paid payslip can no longer change.
        $this->putJson('/api/payroll/payslips/'.$paid[$this->omar->id]->id.'/payment', ['payment_method' => 'cheque'])->assertUnprocessable();
        // The contract's default carries into the renewal.
        $first = StaffContract::where('user_id', $this->sara->id)->value('id');
        $new = $this->postJson("/api/payroll/contracts/$first/renew", ['raise_type' => 'none', 'ends_on' => '2028-08-31'])->assertCreated()->json('data');
        $this->assertSame('bank_transfer', $new['payment_method']);
    }

    public function test_a_payslip_without_a_method_needs_one_at_payment(): void
    {
        $this->contract($this->sara)->assertCreated();
        $runId = $this->postJson('/api/payroll/runs', ['month' => '2026-10'])->assertCreated()->json('data.id');
        $this->postJson("/api/payroll/runs/$runId/submit")->assertOk();
        Sanctum::actingAs($this->principal);
        $this->postJson("/api/payroll/runs/$runId/approve")->assertOk();
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/payroll/runs/$runId/pay", [])->assertUnprocessable();
        $this->postJson("/api/payroll/runs/$runId/pay", ['method' => 'cash'])->assertOk();
    }

    public function test_staff_see_their_own_salary(): void
    {
        $this->contract($this->sara, ['payment_method' => 'cheque'])->assertCreated();
        Sanctum::actingAs($this->sara);
        $this->getJson('/api/payroll/my-contract')->assertOk()->assertJsonPath('data.base_salary', '900.00')->assertJsonPath('data.payment_method', 'cheque');
        Sanctum::actingAs($this->omar);
        $this->getJson('/api/payroll/my-contract')->assertOk()->assertJsonPath('data', null);
    }

    public function test_the_accountant_writes_a_contract_with_monthly_allowances_and_deductions(): void
    {
        $r = $this->contract($this->sara, ['items' => [['kind' => 'allowance', 'label' => 'Transport', 'amount' => 50], ['kind' => 'deduction', 'label' => 'Insurance', 'amount' => 20.5]]])->assertCreated();

        $this->assertSame(['active', '900.00'], [$r->json('data.status'), $r->json('data.base_salary')]);
        $this->assertSame(['Transport', 'Insurance'], array_column($r->json('data.items'), 'label'));
        $list = collect($this->getJson('/api/payroll/staff')->assertOk()->json('data'));
        $this->assertSame(1, $list->firstWhere('id', $this->sara->id)['contracts_count']);
        $this->assertNull($list->firstWhere('id', $this->omar->id)['contract']); // Omar has none yet
        $this->assertSame('JOD', $this->getJson('/api/payroll/staff')->json('currency'));
    }

    public function test_a_contract_is_checked(): void
    {
        $this->contract($this->sara)->assertCreated();
        $this->contract($this->sara)->assertUnprocessable()->assertJsonValidationErrors('user_id'); // already has a running one
        $this->contract($this->omar, ['base_salary' => 0])->assertUnprocessable();
        $this->contract($this->omar, ['ends_on' => '2026-08-31'])->assertUnprocessable(); // ends before it starts
        $this->contract($this->omar, ['items' => [['kind' => 'bonus', 'label' => 'x', 'amount' => 5]]])->assertUnprocessable(); // a bonus is one-off, not monthly
        $parent = User::factory()->role(Role::Guardian)->create();
        $this->contract($parent)->assertUnprocessable()->assertJsonValidationErrors('user_id'); // not staff
        $this->contract(User::factory()->staff(StaffType::Teacher)->create(['is_active' => false]))->assertUnprocessable();
    }

    public function test_renewing_gives_the_annual_raise_as_a_percent_an_amount_or_a_new_salary(): void
    {
        $first = $this->contract($this->sara, ['items' => [['kind' => 'allowance', 'label' => 'Transport', 'amount' => 50]]])->json('data.id');

        $new = $this->postJson("/api/payroll/contracts/$first/renew", ['raise_type' => 'percent', 'raise_value' => 5, 'ends_on' => '2028-08-31'])->assertCreated()->json('data');

        $this->assertSame(['945.00', '2027-09-01', '2028-08-31', 'active', $first], [$new['base_salary'], $new['starts_on'], $new['ends_on'], $new['status'], $new['renewed_from_id']]); // starts the day after the old one ends
        $this->assertEquals([45, 5], [$new['raise_amount'], $new['raise_percent']]);
        $this->assertSame(['Transport'], array_column($new['items'], 'label')); // the allowance carries over
        $this->assertSame('renewed', StaffContract::find($first)->status);

        $amount = $this->postJson("/api/payroll/contracts/{$new['id']}/renew", ['raise_type' => 'amount', 'raise_value' => 100, 'ends_on' => '2029-08-31', 'carry_items' => false])->assertCreated()->json('data');
        $this->assertSame(['1045.00', []], [$amount['base_salary'], $amount['items']]);

        $salary = $this->postJson("/api/payroll/contracts/{$amount['id']}/renew", ['raise_type' => 'salary', 'raise_value' => 1200, 'ends_on' => '2030-08-31'])->assertCreated()->json('data');
        $this->assertEquals([155, 14.83], [$salary['raise_amount'], $salary['raise_percent']]);

        $same = $this->postJson("/api/payroll/contracts/{$salary['id']}/renew", ['raise_type' => 'none', 'ends_on' => '2031-08-31'])->assertCreated()->json('data');
        $this->assertSame('1200.00', $same['base_salary']);
        $this->assertCount(5, $this->getJson("/api/payroll/staff/{$this->sara->id}/contracts")->json('data')); // the whole history stays
    }

    public function test_a_renewal_needs_a_running_contract_a_real_salary_and_sane_dates(): void
    {
        $id = $this->contract($this->sara)->json('data.id');
        $this->postJson("/api/payroll/contracts/$id/renew", ['raise_type' => 'percent'])->assertUnprocessable(); // a raise needs its value
        $this->postJson("/api/payroll/contracts/$id/renew", ['raise_type' => 'salary', 'raise_value' => 0])->assertUnprocessable();
        $this->postJson("/api/payroll/contracts/$id/renew", ['raise_type' => 'none', 'starts_on' => '2027-09-01', 'ends_on' => '2027-08-01'])->assertUnprocessable();
        $this->postJson("/api/payroll/contracts/$id/renew", ['raise_type' => 'none', 'ends_on' => '2028-08-31'])->assertCreated();
        $this->postJson("/api/payroll/contracts/$id/renew", ['raise_type' => 'none', 'ends_on' => '2029-08-31'])->assertUnprocessable(); // already renewed
    }

    public function test_a_contract_can_be_corrected_or_ended_while_it_runs_and_not_after(): void
    {
        $id = $this->contract($this->sara, ['items' => [['kind' => 'allowance', 'label' => 'Transport', 'amount' => 50]]])->json('data.id');

        $this->putJson("/api/payroll/contracts/$id", ['base_salary' => 950, 'notes' => 'Corrected', 'items' => [['kind' => 'deduction', 'label' => 'Loan', 'amount' => 30]]])->assertOk()->assertJsonPath('data.base_salary', '950.00');
        $this->assertSame(['Loan'], array_column($this->getJson("/api/payroll/staff/{$this->sara->id}/contracts")->json('data.0.items'), 'label')); // the list was replaced
        $this->putJson("/api/payroll/contracts/$id", ['ends_on' => '2026-08-01'])->assertUnprocessable();

        $this->postJson("/api/payroll/contracts/$id/end", ['ends_on' => '2026-12-31'])->assertOk()->assertJsonPath('data.status', 'ended');
        $this->putJson("/api/payroll/contracts/$id", ['notes' => 'late'])->assertUnprocessable();
        $this->postJson("/api/payroll/contracts/$id/end")->assertUnprocessable();
    }

    public function test_contracts_ending_soon_are_listed_and_the_accountant_and_principal_are_reminded_once_a_week(): void
    {
        $this->contract($this->sara, ['ends_on' => '2026-11-30'])->assertCreated();    // 20 days left
        $this->contract($this->omar, ['ends_on' => '2027-08-31'])->assertCreated();    // far away

        $soon = $this->getJson('/api/payroll/contracts/expiring?days=60')->assertOk()->json('data');
        $this->assertSame([['Sara', 20]], array_map(fn ($c) => [$c['name'], $c['days_left']], $soon));

        $this->assertSame(1, app(PayrollService::class)->remindExpiring(30));
        $this->assertSame('/teacher/payroll/contracts', $this->notice($this->accountant, 'contract_expiring')['link']);
        $this->assertSame('/admin/payroll/contracts', $this->notice($this->principal, 'contract_expiring')['link']);
        $this->assertSame(0, app(PayrollService::class)->remindExpiring(30)); // not again the same week
        Carbon::setTestNow('2026-11-18 09:00:00');
        $this->assertSame(1, app(PayrollService::class)->remindExpiring(30));
    }

    public function test_only_the_accountant_manages_the_principal_reads_and_others_see_nothing(): void
    {
        $this->contract($this->sara)->assertCreated();

        Sanctum::actingAs($this->principal);
        $this->getJson('/api/payroll/staff')->assertOk();
        $this->contract($this->omar)->assertForbidden();
        Sanctum::actingAs($this->sara);
        $this->getJson('/api/payroll/staff')->assertForbidden();
        $this->getJson('/api/payroll/contracts/expiring')->assertForbidden();
        Sanctum::actingAs(User::factory()->role(Role::Guardian)->create());
        $this->getJson('/api/payroll/staff')->assertForbidden();
    }

    // ---- payroll ---------------------------------------------------------------------------------------------------

    private function prepared(string $month = '2026-11'): array
    {
        $this->contract($this->sara, ['base_salary' => 900, 'items' => [['kind' => 'allowance', 'label' => 'Transport', 'amount' => 50], ['kind' => 'deduction', 'label' => 'Insurance', 'amount' => 20]]]);
        $this->contract($this->omar, ['base_salary' => 1000]);
        $run = $this->postJson('/api/payroll/runs', ['month' => $month])->assertCreated()->json('data');

        return [$run, collect($run['payslips'])->keyBy(fn ($p) => $p['user']['name'])];
    }

    public function test_the_payroll_of_a_month_has_a_payslip_for_each_person_with_a_contract(): void
    {
        [$run, $slips] = $this->prepared();

        $this->assertSame(['2026-11', 'draft'], [$run['month'], $run['status']]);
        $this->assertSame(['Omar', 'Sara'], $slips->keys()->sort()->values()->all());
        $sara = $slips['Sara'];
        $this->assertSame(['900.00', '50.00', '20.00', '930.00'], [$sara['base_salary'], $sara['additions'], $sara['deductions'], $sara['net']]); // 900 + 50 - 20
        $this->assertSame(['allowance', 'deduction'], array_column($sara['items'], 'kind'));
        $this->assertSame('1000.00', $slips['Omar']['net']);
        $this->assertSame(['base' => '1900.00', 'additions' => '50.00', 'deductions' => '20.00', 'net' => '1930.00'], $run['totals']);
    }

    public function test_a_person_without_a_contract_that_month_is_left_out(): void
    {
        $this->contract($this->sara, ['starts_on' => '2026-12-01', 'ends_on' => '2027-11-30']); // starts next month
        $this->contract($this->omar, ['starts_on' => '2026-01-01', 'ends_on' => '2026-09-30']); // ended before this month

        $run = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->assertCreated()->json('data');
        $this->assertSame([], $run['payslips']);
        $this->postJson("/api/payroll/runs/{$run['id']}/submit")->assertUnprocessable(); // nothing to pay
    }

    public function test_in_the_month_a_contract_is_renewed_the_new_contract_with_the_raise_is_paid(): void
    {
        $id = $this->contract($this->sara, ['ends_on' => '2026-11-15'])->json('data.id');
        $this->postJson("/api/payroll/contracts/$id/renew", ['raise_type' => 'amount', 'raise_value' => 100, 'ends_on' => '2027-11-15'])->assertCreated();

        $run = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->json('data');
        $this->assertSame('1000.00', $run['payslips'][0]['base_salary']); // the renewed contract starts on 16 November
        $october = $this->postJson('/api/payroll/runs', ['month' => '2026-10'])->json('data');
        $this->assertSame('900.00', $october['payslips'][0]['base_salary']); // the old rate for the earlier month
    }

    public function test_preparing_again_adds_newcomers_and_keeps_what_was_already_written(): void
    {
        [$run, $slips] = $this->prepared();
        $this->postJson("/api/payroll/payslips/{$slips['Omar']['id']}/items", ['kind' => 'bonus', 'label' => 'Exam supervision', 'amount' => 75])->assertCreated();
        $late = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Huda']);
        $this->contract($late, ['base_salary' => 800]);

        $again = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->json('data');

        $this->assertSame($run['id'], $again['id']);
        $this->assertSame(['Huda', 'Omar', 'Sara'], collect($again['payslips'])->pluck('user.name')->sort()->values()->all());
        $this->assertSame('1075.00', collect($again['payslips'])->firstWhere('user.name', 'Omar')['net']); // the bonus is still there
        $this->assertSame(1, PayrollRun::count());
    }

    public function test_bonuses_and_deductions_change_the_net_and_a_deduction_cannot_exceed_it(): void
    {
        [, $slips] = $this->prepared();
        $id = $slips['Omar']['id'];

        $bonus = $this->postJson("/api/payroll/payslips/$id/items", ['kind' => 'bonus', 'label' => 'Best teacher', 'amount' => 150])->assertCreated()->json('data');
        $this->assertSame(['150.00', '1150.00'], [$bonus['additions'], $bonus['net']]);
        $slip = $this->postJson("/api/payroll/payslips/$id/items", ['kind' => 'deduction', 'label' => 'Two days absent', 'amount' => 66.67])->assertCreated()->json('data');
        $this->assertSame(['66.67', '1083.33'], [$slip['deductions'], $slip['net']]);

        $this->postJson("/api/payroll/payslips/$id/items", ['kind' => 'deduction', 'label' => 'Too much', 'amount' => 5000])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/payroll/payslips/$id/items", ['kind' => 'bonus', 'label' => 'Zero', 'amount' => 0])->assertUnprocessable();
        $this->postJson("/api/payroll/payslips/$id/items", ['kind' => 'gift', 'label' => 'x', 'amount' => 5])->assertUnprocessable();

        $itemId = collect($slip['items'])->firstWhere('label', 'Best teacher')['id'];
        $after = $this->deleteJson("/api/payroll/payslip-items/$itemId")->assertOk()->json('data');
        $this->assertSame('933.33', $after['net']); // 1000 - 66.67
        $this->deleteJson("/api/payroll/payslips/$id")->assertNoContent(); // Omar is not paid this month
        $this->assertNull(Payslip::find($id));
    }

    public function test_the_payroll_goes_draft_submitted_approved_paid_and_each_step_belongs_to_the_right_person(): void
    {
        [$run, $slips] = $this->prepared();
        $id = $run['id'];

        $this->postJson("/api/payroll/runs/$id/approve")->assertForbidden(); // the accountant cannot approve their own work
        $this->postJson("/api/payroll/runs/$id/approve")->assertForbidden();
        $this->postJson("/api/payroll/runs/$id/pay", ['method' => 'cash'])->assertUnprocessable(); // not approved yet

        $this->postJson("/api/payroll/runs/$id/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertSame('/admin/payroll', $this->notice($this->principal, 'payroll_submitted')['link']);
        $this->postJson("/api/payroll/payslips/{$slips['Sara']['id']}/items", ['kind' => 'bonus', 'label' => 'x', 'amount' => 10])->assertUnprocessable(); // locked while waiting
        $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->assertUnprocessable();
        $this->deleteJson("/api/payroll/runs/$id")->assertUnprocessable();

        Sanctum::actingAs($this->principal);
        $this->postJson("/api/payroll/runs/$id/approve")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', 'Principal');
        $this->postJson("/api/payroll/runs/$id/pay", ['method' => 'cash'])->assertForbidden(); // paying is the accountant's
        $this->postJson("/api/payroll/runs/$id/approve")->assertUnprocessable(); // once

        Sanctum::actingAs($this->accountant);
        $this->assertSame('/teacher/payroll', $this->notice($this->accountant, 'payroll_approved')['link']);
        $this->postJson("/api/payroll/runs/$id/pay", ['method' => 'bank_transfer', 'reference' => 'TRX-1'])->assertOk()->assertJsonPath('paid', 2)->assertJsonPath('data.status', 'paid');
        $this->assertSame(['bank_transfer', 'TRX-1', '2026-11-10'], [Payslip::first()->payment_method, Payslip::first()->reference, Payslip::first()->paid_on->format('Y-m-d')]);
        $this->assertSame('/teacher/payslips', $this->notice($this->sara, 'payslip_paid')['link']);
    }

    public function test_the_principal_can_send_it_back_with_a_reason_and_the_accountant_fixes_it(): void
    {
        [$run, $slips] = $this->prepared();
        $this->postJson("/api/payroll/runs/{$run['id']}/submit")->assertOk();

        Sanctum::actingAs($this->principal);
        $this->postJson("/api/payroll/runs/{$run['id']}/send-back", ['note' => 'Omar was on leave'])->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.return_note', 'Omar was on leave');

        Sanctum::actingAs($this->accountant);
        $this->assertStringContainsString('Omar was on leave', json_encode($this->notice($this->accountant, 'payroll_returned')));
        $this->postJson("/api/payroll/payslips/{$slips['Omar']['id']}/items", ['kind' => 'deduction', 'label' => 'Unpaid leave', 'amount' => 100])->assertCreated();
        $this->postJson("/api/payroll/runs/{$run['id']}/submit")->assertOk()->assertJsonPath('data.return_note', null);
    }

    public function test_one_payslip_can_be_paid_alone_and_the_run_is_paid_when_the_last_one_is(): void
    {
        [$run, $slips] = $this->prepared();
        $this->postJson("/api/payroll/runs/{$run['id']}/submit");
        Sanctum::actingAs($this->principal);
        $this->postJson("/api/payroll/runs/{$run['id']}/approve");
        Sanctum::actingAs($this->accountant);

        $first = $this->postJson("/api/payroll/runs/{$run['id']}/pay", ['method' => 'cash', 'payslip_id' => $slips['Sara']['id']])->assertOk()->assertJsonPath('paid', 1)->json('data');
        $this->assertSame('approved', $first['status']);
        $this->postJson("/api/payroll/runs/{$run['id']}/pay", ['method' => 'cash', 'payslip_id' => $slips['Sara']['id']])->assertOk()->assertJsonPath('paid', 0); // not twice
        $this->postJson("/api/payroll/runs/{$run['id']}/pay", ['method' => 'cash'])->assertOk()->assertJsonPath('paid', 1)->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/payroll/runs/{$run['id']}/pay", ['method' => 'cash', 'paid_on' => '2027-01-01'])->assertUnprocessable(); // a payment cannot be in the future
    }

    public function test_a_person_sees_their_own_payslip_only_once_it_is_paid(): void
    {
        [$run, $slips] = $this->prepared();
        $sara = $slips['Sara']['id'];
        $omar = $slips['Omar']['id'];

        Sanctum::actingAs($this->sara);
        $this->assertSame([], $this->getJson('/api/payroll/payslips/mine')->assertOk()->json('data'));
        $this->getJson("/api/payroll/payslips/$sara")->assertForbidden(); // not paid yet

        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/payroll/runs/{$run['id']}/submit");
        Sanctum::actingAs($this->principal);
        $this->postJson("/api/payroll/runs/{$run['id']}/approve");
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/payroll/runs/{$run['id']}/pay", ['method' => 'cash']);

        Sanctum::actingAs($this->sara);
        $mine = $this->getJson('/api/payroll/payslips/mine')->json('data');
        $this->assertSame([['2026-11', '930.00']], array_map(fn ($p) => [$p['month'], $p['net']], $mine));
        $slip = $this->getJson("/api/payroll/payslips/$sara")->assertOk()->json();
        $this->assertSame(['930.00', '2026-11', 'JOD'], [$slip['data']['net'], $slip['data']['month'], $slip['currency']]);
        $this->getJson("/api/payroll/payslips/$omar")->assertForbidden(); // a colleague's

        Sanctum::actingAs($this->principal);
        $this->getJson("/api/payroll/payslips/$omar")->assertOk(); // the management reads any
    }

    public function test_a_draft_payroll_can_be_dropped_and_the_runs_list_has_the_totals(): void
    {
        [$run] = $this->prepared();
        $runs = $this->getJson('/api/payroll/runs')->assertOk()->json('data');
        $this->assertSame([2, '1930.00', 'draft'], [$runs[0]['payslips_count'], $runs[0]['total_net'], $runs[0]['status']]);

        $this->deleteJson("/api/payroll/runs/{$run['id']}")->assertNoContent();
        $this->assertSame(0, Payslip::count());
        $this->postJson('/api/payroll/runs', ['month' => 'November'])->assertUnprocessable();
    }

    public function test_every_salary_change_is_audited(): void
    {
        $id = $this->contract($this->sara)->json('data.id');
        $this->postJson("/api/payroll/contracts/$id/renew", ['raise_type' => 'percent', 'raise_value' => 10, 'ends_on' => '2028-08-31']);

        $this->assertGreaterThanOrEqual(2, AuditLog::where('auditable_type', StaffContract::class)->where('action', 'created')->count());
        $this->assertTrue(AuditLog::where('auditable_type', StaffContract::class)->where('action', 'updated')->where('user_id', $this->accountant->id)->exists()); // the old one became "renewed", by the accountant
    }
}
