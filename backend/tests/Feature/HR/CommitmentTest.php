<?php

namespace Tests\Feature\HR;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Subject;
use App\Modules\HR\Models\StaffAttendance;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Academic\AcademicTestCase;

class CommitmentTest extends AcademicTestCase
{
    private User $accountant;

    private User $principal;

    private User $sara;

    private User $omar;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 07:50:00'); // a Tuesday; the day starts at 07:45 with 10 minutes of grace
        $this->accountant = User::factory()->staff(StaffType::Accountant)->create(['name' => 'Accountant']);
        $this->principal = User::factory()->staff(StaffType::Principal)->create(['name' => 'Principal']);
        $this->sara = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Sara']);
        $this->omar = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Omar']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Sun-Thu working days of November 2026 up to the 10th: 1, 2, 3, 4, 5, 8, 9, 10. */
    private const DAYS = ['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-08', '2026-11-09', '2026-11-10'];

    private function day(User $u, string $date, string $status): void
    {
        StaffAttendance::create(['user_id' => $u->id, 'date' => $date, 'status' => $status, 'check_in' => in_array($status, ['present', 'late']) ? ($status === 'late' ? '08:30:00' : '07:40:00') : null]);
    }

    private function rule(string $code, array $r): void
    {
        Sanctum::actingAs($this->principal);
        $this->putJson('/api/payroll/rules', [$code => ['enabled' => true, ...$r]])->assertOk();
    }

    public function test_checking_in_on_time_late_and_twice(): void
    {
        Sanctum::actingAs($this->sara);
        $this->postJson('/api/staff-attendance/check-in')->assertOk()->assertJsonPath('data.status', 'present')->assertJsonPath('data.check_in', '07:50');
        Carbon::setTestNow('2026-11-10 08:30:00');
        $this->postJson('/api/staff-attendance/check-in')->assertOk()->assertJsonPath('data.check_in', '07:50'); // the first time counts
        $this->postJson('/api/staff-attendance/check-out')->assertOk()->assertJsonPath('data.check_out', '08:30');
        $this->assertSame(40, StaffAttendance::where('user_id', $this->sara->id)->value('worked_minutes'));

        Sanctum::actingAs($this->omar);
        Carbon::setTestNow('2026-11-11 08:10:00');
        $this->postJson('/api/staff-attendance/check-in')->assertOk()->assertJsonPath('data.status', 'late');
        $this->getJson('/api/staff-attendance/me')->assertOk()->assertJsonPath('data.workday', true)->assertJsonPath('data.today.status', 'late');
    }

    public function test_a_day_off_cannot_be_checked_in_and_check_out_needs_a_check_in(): void
    {
        Sanctum::actingAs($this->sara);
        Carbon::setTestNow('2026-11-13 08:00:00'); // Friday
        $this->postJson('/api/staff-attendance/check-in')->assertUnprocessable();
        Carbon::setTestNow('2026-11-10 08:00:00');
        $this->postJson('/api/staff-attendance/check-out')->assertUnprocessable();
    }

    public function test_management_sees_the_day_and_the_principal_fixes_it(): void
    {
        $this->day($this->sara, '2026-11-10', 'present');
        Sanctum::actingAs($this->accountant);
        $rows = collect($this->getJson('/api/staff-attendance/day?date=2026-11-10')->assertOk()->json('data'))->keyBy(fn ($r) => $r['user']['name']);
        $this->assertSame('present', $rows['Sara']['status']);
        $this->assertNull($rows['Omar']['status']);
        $this->putJson("/api/staff-attendance/{$this->omar->id}", ['date' => '2026-11-10', 'status' => 'leave'])->assertForbidden(); // only the principal edits
        Sanctum::actingAs($this->sara);
        $this->getJson('/api/staff-attendance/day')->assertForbidden();

        Sanctum::actingAs($this->principal);
        $this->putJson("/api/staff-attendance/{$this->omar->id}", ['date' => '2026-11-10', 'status' => 'present', 'check_in' => '08:20'])->assertOk()->assertJsonPath('data.status', 'late'); // the time decides
        $this->putJson("/api/staff-attendance/{$this->omar->id}", ['date' => '2026-11-10', 'status' => 'leave', 'note' => 'Sick'])->assertOk()->assertJsonPath('data.check_in', null);
    }

    public function test_the_punctuality_score_counts_on_time_days_and_leave_is_not_held_against_anyone(): void
    {
        foreach (array_slice(self::DAYS, 0, 5) as $d) {
            $this->day($this->sara, $d, 'present');
        }
        $this->day($this->sara, '2026-11-08', 'late');
        $this->day($this->sara, '2026-11-09', 'leave'); // not counted; 2026-11-10 has no record: an absence
        Sanctum::actingAs($this->sara);
        $p = $this->getJson('/api/payroll/commitment?month=2026-11')->assertOk()->json('scores.punctuality');
        $this->assertSame(['score' => 71, 'done' => 5, 'expected' => 7, 'absent' => 1, 'absent_dates' => ['2026-11-10']], $p); // 5 on time out of the 7 days without leave
    }

    public function test_summaries_and_weekly_plans_are_scored_from_the_timetable(): void
    {
        $year = AcademicYear::factory()->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true]);
        $room = Classroom::factory()->create(['grade_level_id' => GradeLevel::factory()->create()->id, 'academic_year_id' => $year->id]);
        $subject = Subject::factory()->create();
        // Sara teaches this class on Sunday and Tuesday (period day 0 and 2).
        foreach ([0, 2] as $dow) {
            $periodId = DB::table('periods')->insertGetId(['day_of_week' => $dow, 'number' => 1, 'starts_at' => '07:45', 'ends_at' => '08:30', 'is_break' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('timetable_entries')->insert(['academic_year_id' => $year->id, 'classroom_id' => $room->id, 'period_id' => $periodId, 'subject_id' => $subject->id, 'teacher_id' => $this->sara->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        // November 1 (Sun), 3 (Tue), 8 (Sun), 10 (Tue): four lessons so far; she wrote the summary for two of them.
        foreach (['2026-11-01', '2026-11-08'] as $d) {
            DB::table('daily_summaries')->insert(['classroom_id' => $room->id, 'subject_id' => $subject->id, 'teacher_id' => $this->sara->id, 'date' => $d, 'summary' => 'x', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        // Weekly plan: weeks starting Nov 1 and Nov 8 (Sunday); she wrote one.
        DB::table('lesson_plans')->insert(['teacher_id' => $this->sara->id, 'classroom_id' => $room->id, 'subject_id' => $subject->id, 'week_starts_on' => '2026-11-01', 'title' => 'W1', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('lesson_plans')->insert(['teacher_id' => $this->sara->id, 'classroom_id' => $room->id, 'subject_id' => $subject->id, 'week_starts_on' => '2026-11-08', 'title' => 'W2', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]); // a draft does not count

        Sanctum::actingAs($this->sara);
        $scores = $this->getJson('/api/payroll/commitment?month=2026-11')->assertOk()->json('scores');
        $this->assertSame(['score' => 50, 'done' => 2, 'expected' => 4], $scores['daily_summary']);
        $this->assertSame(['score' => 50, 'done' => 1, 'expected' => 2], $scores['weekly_plan']);

        Sanctum::actingAs($this->omar); // no lessons: the measure does not apply
        $this->assertNull($this->getJson('/api/payroll/commitment?month=2026-11')->json('scores.daily_summary.score'));
    }

    public function test_the_rules_add_a_bonus_or_a_deduction_when_the_payroll_is_prepared(): void
    {
        foreach (self::DAYS as $d) {
            $this->day($this->sara, $d, 'present');
        }
        foreach (array_slice(self::DAYS, 0, 2) as $d) {
            $this->day($this->omar, $d, 'present'); // 2 of 8 = 25%
        }
        // A twenty bonus from 90%; ten percent of the salary lost below 70%.
        $this->rule('punctuality', ['bonus_min' => 90, 'bonus_type' => 'amount', 'bonus_value' => 20, 'deduction_below' => 70, 'deduction_type' => 'percent', 'deduction_value' => 10]);
        Sanctum::actingAs($this->accountant);
        foreach ([$this->sara, $this->omar] as $u) {
            $this->postJson('/api/payroll/contracts', ['user_id' => $u->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'base_salary' => 300])->assertCreated();
        }
        $runId = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->assertCreated()->json('data.id');
        $slips = collect($this->getJson("/api/payroll/runs/$runId")->json('data.payslips'))->keyBy(fn ($s) => $s['user']['name']);

        $this->assertSame('320.00', $slips['Sara']['net']);
        $this->assertSame('bonus', $slips['Sara']['items'][0]['kind']);
        $this->assertSame('punctuality', $slips['Sara']['items'][0]['auto_code']);
        $this->assertSame('100', $slips['Sara']['items'][0]['detail']);
        $this->assertSame('270.00', $slips['Omar']['net']); // 300 - 10%
        $this->assertSame('deduction', $slips['Omar']['items'][0]['kind']);
    }

    public function test_nothing_is_added_until_the_school_switches_a_rule_on_and_a_deduction_never_goes_below_zero(): void
    {
        $this->day($this->omar, '2026-11-01', 'present');
        Sanctum::actingAs($this->accountant);
        $this->postJson('/api/payroll/contracts', ['user_id' => $this->omar->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'base_salary' => 300, 'items' => [['kind' => 'deduction', 'label' => 'Loan', 'amount' => 290]]])->assertCreated();
        $runId = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->json('data.id');
        $this->assertCount(1, $this->getJson("/api/payroll/runs/$runId")->json('data.payslips.0.items')); // rules are off: only the loan
        $this->deleteJson("/api/payroll/runs/$runId")->assertNoContent();

        $this->rule('punctuality', ['deduction_below' => 70, 'deduction_type' => 'amount', 'deduction_value' => 50]);
        Sanctum::actingAs($this->accountant);
        $runId = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->json('data.id');
        $this->assertSame('0.00', $this->getJson("/api/payroll/runs/$runId")->json('data.payslips.0.net')); // 300 - 290 - 10: only what is left
    }

    /** Sara's punctuality for November: `$ontime` of the 8 working days on time, the rest absent. */
    private function punctualityOf(User $u, int $ontime): void
    {
        foreach (array_slice(self::DAYS, 0, $ontime) as $d) {
            $this->day($u, $d, 'present');
        }
    }

    private function net(User $u): string
    {
        Sanctum::actingAs($this->accountant);
        $this->postJson('/api/payroll/contracts', ['user_id' => $u->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'base_salary' => 300])->assertCreated();
        $runId = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->json('data.id');

        return collect($this->getJson("/api/payroll/runs/$runId")->json('data.payslips'))->firstWhere('user.id', $u->id)['net'];
    }

    public function test_the_deeper_the_shortfall_the_bigger_the_deduction_and_only_the_strictest_tier_applies(): void
    {
        $tiers = [['below' => 80, 'type' => 'amount', 'value' => 10], ['below' => 60, 'type' => 'amount', 'value' => 25], ['below' => 50, 'type' => 'percent', 'value' => 20]];
        $this->rule('punctuality', ['deduction_tiers' => $tiers]);
        // The tiers are kept strictest-last: 80, 60, 50 (highest first).
        $this->assertSame([80, 60, 50], array_column($this->getJson('/api/payroll/rules')->json('data.punctuality.deduction_tiers'), 'below'));

        // 7 of 8 on time = 88%: no tier. 6 of 8 = 75%: first tier. 4 of 8 = 50%: second (below 60). 3 of 8 = 38%: the third.
        $this->assertSame('300.00', $this->net($this->userWith(7)));
        $this->assertSame('290.00', $this->net($this->userWith(6)));
        $this->assertSame('275.00', $this->net($this->userWith(4)));
        $this->assertSame('240.00', $this->net($this->userWith(3))); // 20% of 300
    }

    private function userWith(int $ontime): User
    {
        static $n = 0;
        $u = User::factory()->staff(StaffType::Teacher)->create(['name' => 'T'.++$n]);
        $this->punctualityOf($u, $ontime);

        return $u;
    }

    public function test_every_day_of_absence_costs_a_fixed_amount_or_a_part_of_a_day_and_leave_costs_nothing(): void
    {
        $u = $this->userWith(5); // 5 present, 3 absent
        $this->day($this->sara, '2026-11-01', 'present'); // someone has a record, so recording has started
        $this->rule('absence', ['type' => 'amount', 'value' => 12]);
        $this->assertSame('264.00', $this->net($u)); // 300 - 3 x 12
        $line = DB::table('payslip_items')->where('auto_code', 'absence')->first();
        $this->assertSame('3', $line->detail);
        $this->assertSame('deduction', $line->kind);

        $off = $this->userWith(5);
        $this->day($off, '2026-11-08', 'leave'); // two of the absent days are excused: 2026-11-08 now leave
        $this->day($off, '2026-11-09', 'leave');
        $this->assertSame('288.00', $this->net($off)); // 1 absence only
    }

    public function test_my_salary_shows_the_absence_days_and_the_expected_net_of_the_month_at_once(): void
    {
        $u = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Absent teacher']);
        Sanctum::actingAs($this->accountant);
        $this->postJson('/api/payroll/contracts', ['user_id' => $u->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'base_salary' => 300, 'items' => [['kind' => 'allowance', 'label' => 'Transport', 'amount' => 20]]])->assertCreated();
        $this->day($this->sara, '2026-11-01', 'present'); // attendance recording has started
        foreach (array_slice(self::DAYS, 0, 6) as $d) {
            $this->day($u, $d, 'present');
        }
        $this->day($u, '2026-11-09', 'leave'); // the 9th is an excused day; the 10th (today) has no record
        $this->rule('absence', ['type' => 'amount', 'value' => 12]);

        Sanctum::actingAs($u);
        $r = $this->getJson('/api/payroll/commitment?month=2026-11')->assertOk();
        $r->assertJsonPath('scores.punctuality.absent', 1)->assertJsonPath('scores.punctuality.absent_dates', ['2026-11-10']);
        $r->assertJsonPath('estimate.base', '300.00')->assertJsonPath('estimate.additions', '20.00')->assertJsonPath('estimate.deductions', '12.00')->assertJsonPath('estimate.net', '308.00');
        $this->day($u, '2026-11-10', 'absent'); // the same whether it is recorded or simply missing
        $this->getJson('/api/payroll/commitment?month=2026-11')->assertJsonPath('estimate.net', '308.00');
    }

    public function test_absences_recorded_after_the_payroll_was_prepared_are_deducted_when_it_is_refreshed_from_attendance(): void
    {
        $this->rule('absence', ['type' => 'amount', 'value' => 12]);
        $u = User::factory()->staff(StaffType::Teacher)->create(['name' => 'Late records']);
        Sanctum::actingAs($this->accountant);
        $this->postJson('/api/payroll/contracts', ['user_id' => $u->id, 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31', 'base_salary' => 300])->assertCreated();
        $this->day($this->sara, '2026-11-01', 'present'); // recording has started, but nothing about $u yet
        foreach (self::DAYS as $d) {
            $this->day($u, $d, 'present');
        }
        $runId = $this->postJson('/api/payroll/runs', ['month' => '2026-11'])->json('data.id');
        $slip = fn () => collect($this->getJson("/api/payroll/runs/$runId")->json('data.payslips'))->firstWhere('user.id', $u->id);
        $this->assertSame('300.00', $slip()['net']);

        // The principal fixes two days to absent afterwards: the slip does not know until it is refreshed.
        StaffAttendance::where('user_id', $u->id)->whereIn('date', ['2026-11-02', '2026-11-03'])->update(['status' => 'absent', 'check_in' => null]);
        $this->assertSame('300.00', $slip()['net']);
        $this->postJson("/api/payroll/runs/$runId/refresh-commitment")->assertOk()->assertJsonPath('refreshed', 1);
        $this->assertSame('276.00', $slip()['net']); // 300 - 2 x 12
        $this->postJson("/api/payroll/runs/$runId/refresh-commitment")->assertOk();
        $this->assertSame('276.00', $slip()['net']); // refreshing again does not charge twice
        $this->assertSame(1, DB::table('payslip_items')->where('auto_code', 'absence')->count());
    }

    public function test_a_day_of_absence_can_cost_a_percent_of_a_days_pay(): void
    {
        $u = $this->userWith(7); // 1 absent day
        $this->rule('absence', ['type' => 'percent', 'value' => 100]); // one day of pay per absence: 300 / 22 working days of November (Sun-Thu)
        $this->assertSame('286.36', $this->net($u));
    }

    public function test_only_the_principal_changes_the_rules_and_they_are_validated(): void
    {
        Sanctum::actingAs($this->accountant);
        $this->getJson('/api/payroll/rules')->assertOk()->assertJsonPath('data.punctuality.enabled', false)->assertJsonPath('data.grace_minutes', 10);
        $this->putJson('/api/payroll/rules', ['grace_minutes' => 5])->assertForbidden();
        Sanctum::actingAs($this->principal);
        $this->putJson('/api/payroll/rules', ['grace_minutes' => 5, 'weekly_plan' => ['enabled' => true, 'bonus_type' => 'cash']])->assertUnprocessable();
        $this->putJson('/api/payroll/rules', ['grace_minutes' => 5, 'weekly_plan' => ['enabled' => true, 'bonus_value' => 15]])->assertOk()->assertJsonPath('data.weekly_plan.bonus_value', 15)->assertJsonPath('data.grace_minutes', 5);
        Sanctum::actingAs($this->sara);
        $this->getJson('/api/payroll/rules')->assertForbidden();
    }
}
