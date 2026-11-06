<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Finance\Models\Money;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Models\PayslipItem;
use App\Modules\HR\Models\PayrollRuleAssignment;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\PayrollTemplate;
use App\Modules\HR\Models\StaffContract;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Contracts (with renewal and the annual raise) and the monthly payroll. Money is added in cents. */
class PayrollService
{
    public function __construct(private readonly CommitmentService $commitment, private readonly Notifier $notifier) {}

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
                'payment_method' => $data['payment_method'] ?? null, 'payment_details' => $data['payment_details'] ?? null,
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
                'payment_method' => array_key_exists('payment_method', $data) ? $data['payment_method'] : $old->payment_method,
                'payment_details' => array_key_exists('payment_details', $data) ? $data['payment_details'] : $old->payment_details,
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
                $slip = $run->payslips()->create(['user_id' => $staff->id, 'staff_contract_id' => $contract->id, 'base_salary' => $contract->base_salary, 'net' => $contract->base_salary, 'payment_method' => $contract->payment_method, 'payment_details' => $contract->payment_details]);
                foreach ($contract->items as $item) {
                    $slip->items()->create(['kind' => $item->kind, 'label' => $item->label, 'amount' => $item->amount, 'from_contract' => true, 'created_by' => $by->id]);
                }
                $this->addCommitmentLines($slip, $staff, $start, $by);
                $this->addAssignedRules($slip, $staff, $start, $by);
                $this->recalculate($slip);
            }
        });

        return $run->refresh();
    }

    /**
     * Works the automatic lines (punctuality, summaries, plans, absence) out again for every payslip of a draft payroll, from the attendance
     * and rules as they are now. Lines the accountant added by hand, contract items and assigned rules are not touched.
     */
    public function refreshCommitment(PayrollRun $run, User $by): int
    {
        $this->assertDraft($run);
        $start = $run->month->copy()->startOfMonth();
        DB::transaction(function () use ($run, $start, $by): void {
            foreach ($run->payslips()->with('user')->get() as $slip) {
                $slip->items()->whereNotNull('auto_code')->delete();
                $this->addCommitmentLines($slip->refresh(), $slip->user, $start, $by);
                $this->recalculate($slip->refresh());
            }
        });

        return $run->payslips()->count();
    }

    /** The school's own rules this person was assigned for this month (a summer club, private lessons...). A deduction that would take the net below zero is skipped. */
    private function addAssignedRules(Payslip $slip, User $staff, Carbon $month, User $by): void
    {
        $assignments = PayrollRuleAssignment::with('template')->where('user_id', $staff->id)->whereDate('starts_on', '<=', $month->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $month->toDateString()))->get();
        foreach ($assignments as $assignment) {
            $template = $assignment->template;
            if (! $template->is_active) {
                continue;
            }
            $slip->refresh();
            $cents = $template->type === 'percent' ? (int) round(Money::cents($slip->base_salary) * (float) $template->value / 100) : Money::cents($template->value);
            $net = Money::cents($slip->net) + ($template->kind === 'deduction' ? -$cents : $cents);
            if ($cents <= 0 || $net < 0) {
                continue;
            }
            $slip->items()->create(['kind' => $template->kind, 'label' => $template->name, 'amount' => Money::amount($cents), 'category' => $template->category, 'created_by' => $by->id]);
            $this->recalculate($slip->refresh());
        }
    }

    /** The bonuses and deductions the commitment rules give this person for the month. A deduction never takes the net below zero. */
    private function addCommitmentLines(Payslip $slip, User $staff, Carbon $month, User $by): void
    {
        $room = Money::cents($slip->base_salary) + $slip->items()->whereIn('kind', ['bonus', 'allowance'])->get()->sum(fn ($i) => Money::cents($i->amount)) - $slip->items()->where('kind', 'deduction')->get()->sum(fn ($i) => Money::cents($i->amount));
        foreach ($this->commitment->lines($staff, $month, $slip->base_salary) as $line) {
            $cents = Money::cents($line['amount']);
            if ($line['kind'] === 'deduction') {
                $cents = min($cents, max(0, $room));
                $room -= $cents;
            }
            if ($cents <= 0) {
                continue;
            }
            $slip->items()->create(['kind' => $line['kind'], 'label' => __('payroll.auto.'.$line['code'].'_'.$line['kind'], ['score' => $line['score']]), 'amount' => Money::amount($cents), 'from_contract' => false, 'auto_code' => $line['code'], 'detail' => (string) $line['score'], 'created_by' => $by->id]);
        }
    }

    /** Adds up a payslip: net = base + bonuses and allowances - deductions. */
    public function recalculate(Payslip $slip): void
    {
        $items = $slip->items()->get();
        $additions = $items->whereIn('kind', ['bonus', 'allowance'])->sum(fn ($i) => Money::cents($i->amount));
        $deductions = $items->where('kind', 'deduction')->sum(fn ($i) => Money::cents($i->amount));
        $slip->update(['additions' => Money::amount($additions), 'deductions' => Money::amount($deductions), 'net' => Money::amount(Money::cents($slip->base_salary) + $additions - $deductions)]);
    }

    public function addItem(Payslip $slip, string $kind, string $label, float|int|string $amount, User $by, ?string $category = null): PayslipItem
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

        return DB::transaction(function () use ($slip, $kind, $label, $cents, $by, $category): PayslipItem {
            $item = $slip->items()->create(['kind' => $kind, 'label' => trim($label), 'amount' => Money::amount($cents), 'category' => $category, 'created_by' => $by->id]);
            $this->recalculate($slip->refresh());

            return $item;
        });
    }

    /**
     * Gives the school's own rule to some payslips of a draft payroll (or to all of them): a percent rule is worked out from each
     * person's base salary. A deduction that would take someone's net below zero is skipped for that person.
     *
     * @param  list<int>|null  $payslipIds  null = everyone on the payroll
     * @return array{applied: int, skipped: int}
     */
    public function applyTemplate(PayrollRun $run, PayrollTemplate $template, ?array $payslipIds, User $by): array
    {
        $this->assertDraft($run);
        $applied = 0;
        $skipped = 0;
        foreach ($run->payslips()->when($payslipIds !== null, fn ($q) => $q->whereIn('id', $payslipIds))->get() as $slip) {
            $cents = $template->type === 'percent' ? (int) round(Money::cents($slip->base_salary) * (float) $template->value / 100) : Money::cents($template->value);
            try {
                $this->addItem($slip->setRelation('run', $run), $template->kind, $template->name, Money::amount($cents), $by, $template->category);
                $applied++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped];
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
    public function pay(PayrollRun $run, ?Payslip $only, ?string $method, string $paidOn, ?string $reference, ?int $accountId = null): int
    {
        $this->assertStatus($run, 'approved', 'paid');
        $slips = $run->payslips()->whereNull('paid_on')->when($only, fn ($q) => $q->whereKey($only->id))->with('user')->get();
        // Everyone is paid the way their own payslip says; the method given here is only for payslips that have none.
        if ($slips->contains(fn (Payslip $s) => ! $s->payment_method && ! $method)) {
            throw ValidationException::withMessages(['method' => [__('payroll.no_method')]]);
        }
        $book = app(\App\Modules\Finance\Services\MoneyBook::class);
        DB::transaction(function () use ($slips, $method, $paidOn, $reference, $run, $accountId, $book): void {
            foreach ($slips as $slip) {
                $slip->update(['paid_on' => $paidOn, 'payment_method' => $slip->payment_method ?: $method, 'reference' => $slip->reference ?: $reference]);
                // The salary leaves an account: the one chosen, else the cash box for cash and the bank for transfers and cheques (no account, no line).
                $account = $book->accountForSalary($slip->payment_method, $accountId);
                if ($account !== null) {
                    $book->spend($account, $slip->net, $paidOn, \App\Modules\Finance\Models\AccountEntry::PAYROLL, __('finance.salary_line', ['month' => $run->month->format('Y-m'), 'name' => $slip->user->name]), 'payslip', $slip->id, auth()->user());
                }
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

    /** How one payslip is paid this month (the contract's default can be overridden per person and per month). */
    public function setPayment(Payslip $payslip, string $method, ?string $details, ?string $reference): void
    {
        $this->assertStatus($payslip->run, 'draft', 'submitted', 'approved');
        if ($payslip->paid_on !== null) {
            throw ValidationException::withMessages(['payslip' => [__('payroll.already_paid')]]);
        }
        $payslip->update(['payment_method' => $method, 'payment_details' => $details, 'reference' => $reference]);
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
