<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Finance\Models\Money;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Models\PayslipItem;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\StaffContract;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Contracts (with renewal and the annual raise) and the monthly payroll. Money is added in cents. */
class PayrollService
{
    public function __construct(private readonly Notifier $notifier) {}

    // ---- contracts -------------------------------------------------------------------------------------------------

    /**
     * Writes a contract for a member of staff. They can have only one running contract: renew or end it first.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{kind: string, label: string, amount: float|int|string}>  $items
     */
    public function createContract(User $staff, array $data, array $items, User $by): StaffContract
    {
        if (StaffContract::where('user_id', $staff->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['user_id' => [__('payroll.has_active_contract')]]);
        }

        return DB::transaction(function () use ($staff, $data, $items, $by): StaffContract {
            $contract = StaffContract::create([
                'user_id' => $staff->id, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'base_salary' => $data['base_salary'],
                'notes' => $data['notes'] ?? null, 'status' => 'active', 'created_by' => $by->id,
            ]);
            $this->writeItems($contract, $items);

            return $contract;
        });
    }

    /**
     * Renews a running contract: a NEW contract starts where the old one ends, with a raise if given, and the old one
     * is kept as "renewed". The raise is a percent of the salary, a fixed amount, or the new salary itself.
     *
     * @param  array<string, mixed>  $data
     */
    public function renew(StaffContract $old, array $data, User $by): StaffContract
    {
        if ($old->status !== 'active') {
            throw ValidationException::withMessages(['contract' => [__('payroll.not_active')]]);
        }
        $starts = isset($data['starts_on']) ? Carbon::parse($data['starts_on']) : ($old->ends_on?->copy()->addDay() ?? now());
        $ends = isset($data['ends_on']) ? Carbon::parse($data['ends_on']) : null;
        if ($ends !== null && $ends->lte($starts)) {
            throw ValidationException::withMessages(['ends_on' => [__('payroll.dates_order')]]);
        }

        $oldCents = Money::cents($old->base_salary);
        $newCents = match ($data['raise_type'] ?? 'none') {
            'percent' => (int) round($oldCents * (1 + (float) $data['raise_value'] / 100)),
            'amount' => $oldCents + Money::cents($data['raise_value']),
            'salary' => Money::cents($data['raise_value']),
            default => $oldCents,
        };
        if ($newCents <= 0) {
            throw ValidationException::withMessages(['raise_value' => [__('payroll.bad_salary')]]);
        }
        $raise = $newCents - $oldCents;

        return DB::transaction(function () use ($old, $starts, $ends, $newCents, $raise, $oldCents, $data, $by): StaffContract {
            $old->update(['status' => 'renewed']);
            $new = StaffContract::create([
                'user_id' => $old->user_id, 'starts_on' => $starts->toDateString(), 'ends_on' => $ends?->toDateString(), 'base_salary' => Money::amount($newCents),
                'status' => 'active', 'renewed_from_id' => $old->id, 'raise_amount' => Money::amount($raise), 'raise_percent' => $oldCents > 0 ? round($raise / $oldCents * 100, 2) : null,
                'notes' => $data['notes'] ?? null, 'created_by' => $by->id,
            ]);
            // The monthly allowances and deductions carry over unless the accountant says otherwise.
            if ($data['carry_items'] ?? true) {
                $this->writeItems($new, $old->items->map(fn ($i) => ['kind' => $i->kind, 'label' => $i->label, 'amount' => $i->amount])->all());
            }

            return $new;
        });
    }

    public function endContract(StaffContract $contract, ?string $on): void
    {
        if ($contract->status !== 'active') {
            throw ValidationException::withMessages(['contract' => [__('payroll.not_active')]]);
        }
        $on = $on ?? now()->toDateString();
        if (Carbon::parse($on)->lt($contract->starts_on)) {
            throw ValidationException::withMessages(['ends_on' => [__('payroll.dates_order')]]);
        }
        $contract->update(['status' => 'ended', 'ends_on' => $on]);
    }

    /** @param  list<array{kind: string, label: string, amount: float|int|string}>  $items */
    public function writeItems(StaffContract $contract, array $items): void
    {
        $contract->items()->delete();
        foreach ($items as $item) {
            $contract->items()->create(['kind' => $item['kind'], 'label' => trim($item['label']), 'amount' => $item['amount']]);
        }
    }

    // ---- payroll ---------------------------------------------------------------------------------------------------

    /** The contract that pays a person for a month: the latest one that started by the end of the month and had not ended before it. */
    public function contractFor(User $user, Carbon $monthStart): ?StaffContract
    {
        $monthEnd = $monthStart->copy()->endOfMonth();

        return StaffContract::where('user_id', $user->id)->whereDate('starts_on', '<=', $monthEnd)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $monthStart))
            ->orderByDesc('starts_on')->orderByDesc('id')->first();
    }

    /** Opens (or completes) the draft payroll of a month: one payslip for everybody with a contract that month. Payslips already there are kept. */
    public function prepare(string $month, User $by): PayrollRun
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $run = PayrollRun::firstOrCreate(['month' => $start->toDateString()], ['status' => 'draft', 'prepared_by' => $by->id]);
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['month' => [__('payroll.locked')]]);
        }

        DB::transaction(function () use ($run, $start, $by): void {
            $have = $run->payslips()->pluck('user_id');
            foreach (User::whereIn('role', [Role::Admin, Role::Teacher])->where('is_active', true)->whereHas('staffProfile')->whereNotIn('id', $have)->orderBy('name')->get() as $staff) {
                $contract = $this->contractFor($staff, $start);
                if ($contract === null) {
                    continue;
                }
                $slip = $run->payslips()->create(['user_id' => $staff->id, 'staff_contract_id' => $contract->id, 'base_salary' => $contract->base_salary, 'net' => $contract->base_salary]);
                foreach ($contract->items as $item) {
                    $slip->items()->create(['kind' => $item->kind, 'label' => $item->label, 'amount' => $item->amount, 'from_contract' => true, 'created_by' => $by->id]);
                }
                $this->recalculate($slip);
            }
        });

        return $run->refresh();
    }

    /** Adds up a payslip: net = base + bonuses and allowances - deductions. */
    public function recalculate(Payslip $slip): void
    {
        $items = $slip->items()->get();
        $additions = $items->whereIn('kind', ['bonus', 'allowance'])->sum(fn ($i) => Money::cents($i->amount));
        $deductions = $items->where('kind', 'deduction')->sum(fn ($i) => Money::cents($i->amount));
        $slip->update(['additions' => Money::amount($additions), 'deductions' => Money::amount($deductions), 'net' => Money::amount(Money::cents($slip->base_salary) + $additions - $deductions)]);
    }

    public function addItem(Payslip $slip, string $kind, string $label, float|int|string $amount, User $by): PayslipItem
    {
        $this->assertDraft($slip->run);
        $cents = Money::cents($amount);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => [__('payroll.bad_amount')]]);
        }
        $slip->loadMissing('items');
        $net = Money::cents($slip->net) + ($kind === 'deduction' ? -$cents : $cents);
        if ($net < 0) {
            throw ValidationException::withMessages(['amount' => [__('payroll.deduction_too_big', ['net' => $slip->net])]]);
        }

        return DB::transaction(function () use ($slip, $kind, $label, $cents, $by): PayslipItem {
            $item = $slip->items()->create(['kind' => $kind, 'label' => trim($label), 'amount' => Money::amount($cents), 'created_by' => $by->id]);
            $this->recalculate($slip->refresh());

            return $item;
        });
    }

    public function removeItem(PayslipItem $item): void
    {
        $slip = Payslip::with('run')->findOrFail($item->payslip_id);
        $this->assertDraft($slip->run);
        DB::transaction(function () use ($item, $slip): void {
            $item->delete();
            $this->recalculate($slip->refresh());
        });
    }

    /** Removes a payslip (someone who should not be paid this month) from a draft payroll. */
    public function removePayslip(Payslip $slip): void
    {
        $this->assertDraft($slip->run);
        $slip->delete();
    }

    public function submit(PayrollRun $run, User $by): void
    {
        $this->assertDraft($run);
        if (! $run->payslips()->exists()) {
            throw ValidationException::withMessages(['month' => [__('payroll.empty')]]);
        }
        $run->update(['status' => 'submitted', 'submitted_at' => now(), 'return_note' => null, 'prepared_by' => $by->id]);
        $this->notifier->toUsers(User::where('role', Role::Admin)->where('is_active', true)->pluck('id'), 'payroll_submitted', ['month' => $run->month->format('Y-m')], '/admin/payroll');
    }

    public function approve(PayrollRun $run, User $by): void
    {
        $this->assertStatus($run, 'submitted');
        $run->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()]);
        if ($run->prepared_by) {
            $this->notifier->toUsers([$run->prepared_by], 'payroll_approved', ['month' => $run->month->format('Y-m')], '/teacher/payroll');
        }
    }

    /** The principal sends it back to the accountant with a reason. */
    public function sendBack(PayrollRun $run, ?string $note): void
    {
        $this->assertStatus($run, 'submitted');
        $run->update(['status' => 'draft', 'return_note' => $note, 'submitted_at' => null]);
        if ($run->prepared_by) {
            $this->notifier->toUsers([$run->prepared_by], 'payroll_returned', ['month' => $run->month->format('Y-m'), 'note' => $note ?? ''], '/teacher/payroll');
        }
    }

    /**
     * Records the payment of an approved payroll (every unpaid payslip, or only `$only`) and tells each person.
     *
     * @return int how many were paid
     */
    public function pay(PayrollRun $run, ?Payslip $only, string $method, string $paidOn, ?string $reference): int
    {
        $this->assertStatus($run, 'approved', 'paid');
        $slips = $run->payslips()->whereNull('paid_on')->when($only, fn ($q) => $q->whereKey($only->id))->with('user')->get();
        DB::transaction(function () use ($slips, $method, $paidOn, $reference, $run): void {
            foreach ($slips as $slip) {
                $slip->update(['paid_on' => $paidOn, 'payment_method' => $method, 'reference' => $reference]);
            }
            if (! $run->payslips()->whereNull('paid_on')->exists()) {
                $run->update(['status' => 'paid']);
            }
        });
        foreach ($slips as $slip) {
            $this->notifier->toUsers([$slip->user_id], 'payslip_paid', ['month' => $run->month->format('Y-m'), 'amount' => $slip->net], $this->payslipLink($slip->user));
        }

        return $slips->count();
    }

    public function payslipLink(User $user): string
    {
        return $user->hasRole(Role::Admin) ? '/admin/payslips' : '/teacher/payslips';
    }

    /** Tells the accountants and the principal about contracts that end within `$days` days (once a week per contract). @return int how many contracts */
    public function remindExpiring(int $days = 30): int
    {
        $contracts = StaffContract::with('user')->where('status', 'active')->whereNotNull('ends_on')->whereDate('ends_on', '<=', now()->addDays($days))->whereDate('ends_on', '>=', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', now()->subDays(7)))->get();
        if ($contracts->isEmpty()) {
            return 0;
        }
        $accountants = User::where('role', Role::Teacher)->whereHas('staffProfile', fn ($q) => $q->where('staff_type', StaffType::Accountant->value))->pluck('id');
        $admins = User::where('role', Role::Admin)->where('is_active', true)->pluck('id');
        foreach ($contracts as $contract) {
            $params = ['name' => $contract->user->name, 'date' => $contract->ends_on->format('Y-m-d')];
            $this->notifier->toUsers($admins, 'contract_expiring', $params, '/admin/payroll/contracts');
            $this->notifier->toUsers($accountants, 'contract_expiring', $params, '/teacher/payroll/contracts');
            $contract->update(['reminded_at' => now()]);
        }

        return $contracts->count();
    }

    private function assertDraft(PayrollRun $run): void
    {
        $this->assertStatus($run, 'draft');
    }

    private function assertStatus(PayrollRun $run, string ...$allowed): void
    {
        if (! in_array($run->status, $allowed, true)) {
            throw ValidationException::withMessages(['month' => [__('payroll.wrong_state', ['state' => $run->status])]]);
        }
    }
}
