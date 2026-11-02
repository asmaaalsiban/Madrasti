<?php

namespace Tests\Feature\HR;

use App\Models\User;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Models\PayrollRun;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class PayrollTemplatesTest extends AcademicTestCase
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
        $this->postJson('/api/payroll/contracts', ['user_id' => $this->sara->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'base_salary' => 300])->assertCreated();
        $this->postJson('/api/payroll/contracts', ['user_id' => $this->omar->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'base_salary' => 100])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function template(array $extra = []): int
    {
        return $this->postJson('/api/payroll/templates', ['name' => 'Summer club', 'kind' => 'bonus', 'type' => 'amount', 'value' => 50, 'category' => 'summer_club', ...$extra])->assertCreated()->json('data.id');
    }

    public function test_the_accountant_and_the_principal_write_rules_and_nobody_else_does(): void
    {
        $id = $this->template();
        Sanctum::actingAs($this->principal);
        $this->putJson("/api/payroll/templates/$id", ['name' => 'Summer club', 'kind' => 'bonus', 'type' => 'percent', 'value' => 10])->assertOk()->assertJsonPath('data.type', 'percent');
        $this->getJson('/api/payroll/templates')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/payroll/templates', ['name' => 'Bad', 'kind' => 'bonus', 'type' => 'amount', 'value' => 0])->assertUnprocessable();
        $this->postJson('/api/payroll/templates', ['name' => 'Bad', 'kind' => 'bonus', 'type' => 'amount', 'value' => 5, 'category' => 'x'])->assertUnprocessable();
        $this->deleteJson("/api/payroll/templates/$id")->assertNoContent();
        Sanctum::actingAs($this->sara);
        $this->getJson('/api/payroll/templates')->assertForbidden();
        $this->postJson('/api/payroll/templates', ['name' => 'X', 'kind' => 'bonus', 'type' => 'amount', 'value' => 5])->assertForbidden();
    }

    public function test_a_rule_is_given_to_everyone_with_percent_worked_from_each_salary_and_category_kept(): void
    {
        $percent = $this->template(['name' => 'Exam supervision', 'type' => 'percent', 'value' => 10, 'category' => null]);
        $runId = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->json('data.id');
        $r = $this->postJson("/api/payroll/runs/$runId/apply-template", ['template_id' => $percent])->assertOk()->json();
        $this->assertSame(2, $r['applied']);
        $slips = collect($r['data']['payslips'])->keyBy(fn ($s) => $s['user']['name']);
        $this->assertSame('330.00', $slips['Sara']['net']);
        $this->assertSame('110.00', $slips['Omar']['net']);

        $club = $this->template();
        $this->postJson("/api/payroll/runs/$runId/apply-template", ['template_id' => $club, 'payslip_ids' => [$slips['Sara']['id']]])->assertOk()->assertJsonPath('applied', 1);
        $item = Payslip::find($slips['Sara']['id'])->items()->where('category', 'summer_club')->first();
        $this->assertSame('50.00', $item->amount);
        $this->assertSame('Summer club', $item->label);
        $this->assertSame('380.00', Payslip::find($slips['Sara']['id'])->net);
    }

    public function test_a_deduction_rule_skips_whoever_it_would_push_below_zero_and_only_a_draft_takes_rules(): void
    {
        $ded = $this->template(['name' => 'Penalty', 'kind' => 'deduction', 'value' => 150, 'category' => null]);
        $runId = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->json('data.id');
        $r = $this->postJson("/api/payroll/runs/$runId/apply-template", ['template_id' => $ded])->assertOk()->json();
        $this->assertSame(1, $r['applied']); // Sara (300) yes, Omar (100) no
        $this->assertSame(1, $r['skipped']);

        $this->postJson("/api/payroll/runs/$runId/submit")->assertOk();
        $this->postJson("/api/payroll/runs/$runId/apply-template", ['template_id' => $ded])->assertUnprocessable();
        Sanctum::actingAs($this->principal);
        $this->postJson("/api/payroll/runs/$runId/apply-template", ['template_id' => $ded])->assertForbidden(); // the principal writes rules; the accountant applies them
    }

    public function test_a_manual_line_can_have_a_category_and_my_payslips_carry_the_breakdown(): void
    {
        $runId = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->json('data.id');
        $slip = PayrollRun::find($runId)->payslips()->where('user_id', $this->sara->id)->first();
        $this->postJson("/api/payroll/payslips/{$slip->id}/items", ['kind' => 'bonus', 'label' => 'Maths tutoring', 'amount' => 40, 'category' => 'private_lessons'])->assertCreated();
        $this->postJson("/api/payroll/payslips/{$slip->id}/items", ['kind' => 'bonus', 'label' => 'x', 'amount' => 40, 'category' => 'nope'])->assertUnprocessable();
        $this->postJson("/api/payroll/runs/$runId/submit")->assertOk();
        Sanctum::actingAs($this->principal);
        $this->postJson("/api/payroll/runs/$runId/approve")->assertOk();
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/payroll/runs/$runId/pay", ['method' => 'cash'])->assertOk();

        Sanctum::actingAs($this->sara);
        $mine = $this->getJson('/api/payroll/payslips/mine')->assertOk()->json('data.0');
        $this->assertSame('340.00', $mine['net']);
        $this->assertSame('300.00', $mine['base_salary']);
        $this->assertSame('private_lessons', $mine['items'][0]['category']);
    }

    public function test_a_rule_assigned_to_a_person_for_some_months_lands_on_their_payslip_in_those_months_only(): void
    {
        $club = $this->template(['name' => 'Summer club', 'value' => 60]);
        $r = $this->postJson("/api/payroll/templates/$club/assignments", ['user_ids' => [$this->sara->id], 'starts_on' => '2026-11', 'ends_on' => '2026-12'])->assertCreated()->json('data');
        $this->assertSame('Sara', $r[0]['user']['name']);
        $this->assertSame('2026-11', $r[0]['starts_on']);
        $this->getJson('/api/payroll/templates')->assertJsonPath('data.0.assignments_count', 1);

        $nov = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->json('data.id');
        $slips = collect($this->getJson("/api/payroll/runs/$nov")->json('data.payslips'))->keyBy(fn ($s) => $s['user']['name']);
        $this->assertSame('360.00', $slips['Sara']['net']); // 300 + 60
        $this->assertSame('summer_club', $slips['Sara']['items'][0]['category']);
        $this->assertSame('100.00', $slips['Omar']['net']); // not assigned

        $this->postJson('/api/payroll/runs', ['month' => '2027-01'])->assertCreated();
        $jan = PayrollRun::where('month', '2027-01-01')->first();
        $this->assertSame('300.00', $jan->payslips()->where('user_id', $this->sara->id)->value('net')); // the range ended in December
    }

    public function test_an_assignment_can_be_open_ended_removed_and_only_staff_are_assigned(): void
    {
        $id = $this->template(['name' => 'Tutoring', 'category' => 'private_lessons']);
        $student = User::factory()->create(['role' => \App\Shared\Enums\Role::Student]);
        $res = $this->postJson("/api/payroll/templates/$id/assignments", ['user_ids' => [$this->omar->id, $student->id], 'starts_on' => '2026-10'])->assertCreated()->json('data');
        $this->assertCount(1, $res); // the student is not staff
        $this->assertNull($res[0]['ends_on']);
        $this->postJson("/api/payroll/templates/$id/assignments", ['user_ids' => [$this->omar->id], 'starts_on' => '2026-11', 'ends_on' => '2026-10'])->assertUnprocessable();

        $this->deleteJson('/api/payroll/assignments/'.$res[0]['id'])->assertNoContent();
        $this->getJson("/api/payroll/templates/$id/assignments")->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($this->sara);
        $this->postJson("/api/payroll/templates/$id/assignments", ['user_ids' => [$this->sara->id], 'starts_on' => '2026-11'])->assertForbidden();
    }

    public function test_an_inactive_rule_or_a_deduction_that_would_go_below_zero_is_not_assigned_to_the_payslip(): void
    {
        $off = $this->template(['name' => 'Old club', 'is_active' => false]);
        $big = $this->template(['name' => 'Fine', 'kind' => 'deduction', 'value' => 500, 'category' => null]);
        foreach ([$off, $big] as $id) {
            $this->postJson("/api/payroll/templates/$id/assignments", ['user_ids' => [$this->omar->id], 'starts_on' => '2026-11'])->assertCreated();
        }
        $run = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->json('data.id');
        $omar = collect($this->getJson("/api/payroll/runs/$run")->json('data.payslips'))->firstWhere('user.name', 'Omar');
        $this->assertSame('100.00', $omar['net']);
        $this->assertCount(0, $omar['items']);
    }
}
