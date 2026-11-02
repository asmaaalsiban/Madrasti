<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Finance\Models\Money;
use App\Modules\HR\Models\Payslip;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Models\PayslipItem;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Services\PayrollAccess;
use App\Modules\HR\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The monthly payroll. The accountant prepares it, adds bonuses and deductions, submits it and records the payment; the
 * principal approves or sends it back; each person reads their own payslips once paid.
 */
class PayrollController extends Controller
{
    public const METHODS = ['cash', 'bank_transfer', 'cheque'];

    public function __construct(private readonly PayrollService $payroll) {}

    public function runs(Request $request): JsonResponse
    {
        $this->view($request);
        $rows = PayrollRun::withCount('payslips')->withSum('payslips as total_net', 'net')->orderByDesc('month')->get();

        return response()->json(['data' => $rows->map(fn (PayrollRun $r) => $this->presentRun($r) + ['payslips_count' => $r->payslips_count, 'total_net' => Money::amount(Money::cents($r->total_net))])->values(), 'currency' => $this->currency()]);
    }

    /** Opens the draft payroll of a month (or adds the people who joined since). */
    public function prepare(Request $request): JsonResponse
    {
        $by = $this->manage($request);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $run = $this->payroll->prepare($data['month'], $by);

        return response()->json(['data' => $this->detail($run)], 201);
    }

    public function show(Request $request, PayrollRun $run): JsonResponse
    {
        $this->view($request);

        return response()->json(['data' => $this->detail($run), 'currency' => $this->currency()]);
    }

    public function submit(Request $request, PayrollRun $run): JsonResponse
    {
        $by = $this->manage($request);
        $this->payroll->submit($run, $by);

        return response()->json(['data' => $this->detail($run->refresh())]);
    }

    public function approve(Request $request, PayrollRun $run): JsonResponse
    {
        $by = $this->user($request);
        abort_unless(PayrollAccess::approves($by), 403, __('payroll.not_allowed'));
        $this->payroll->approve($run, $by);

        return response()->json(['data' => $this->detail($run->refresh())]);
    }

    public function sendBack(Request $request, PayrollRun $run): JsonResponse
    {
        abort_unless(PayrollAccess::approves($this->user($request)), 403, __('payroll.not_allowed'));
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->payroll->sendBack($run, $data['note'] ?? null);

        return response()->json(['data' => $this->detail($run->refresh())]);
    }

    /** Records the payment of the approved payroll (all unpaid payslips) or of one payslip. */
    public function pay(Request $request, PayrollRun $run): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate([
            'payslip_id' => ['nullable', 'integer'], 'method' => ['nullable', Rule::in(self::METHODS)],
            'paid_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'reference' => ['nullable', 'string', 'max:255'],
        ]);
        $only = isset($data['payslip_id']) ? $run->payslips()->findOrFail($data['payslip_id']) : null;
        $paid = $this->payroll->pay($run, $only, $data['method'] ?? null, $data['paid_on'] ?? now()->toDateString(), $data['reference'] ?? null);

        return response()->json(['data' => $this->detail($run->refresh()), 'paid' => $paid]);
    }

    /** A draft payroll nobody needs any more. */
    public function destroy(Request $request, PayrollRun $run): Response
    {
        $this->manage($request);
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['month' => [__('payroll.wrong_state', ['state' => $run->status])]]);
        }
        $run->delete();

        return response()->noContent();
    }

    public function addItem(Request $request, Payslip $payslip): JsonResponse
    {
        $by = $this->manage($request);
        $data = $request->validate(['kind' => ['required', Rule::in(PayslipItem::KINDS)], 'label' => ['required', 'string', 'max:120'], 'amount' => ['required', 'numeric', 'gt:0', 'max:1000000']]);
        $this->payroll->addItem($payslip, $data['kind'], $data['label'], $data['amount'], $by);

        return response()->json(['data' => $this->presentSlip($payslip->refresh()->load(['items', 'user.staffProfile']))], 201);
    }

    /** How one payslip is paid (bank transfer, cheque, cash) and the details: IBAN, cheque number... */
    public function setPayment(Request $request, Payslip $payslip): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['payment_method' => ['required', Rule::in(self::METHODS)], 'payment_details' => ['nullable', 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:255']]);
        $this->payroll->setPayment($payslip->load('run'), $data['payment_method'], $data['payment_details'] ?? null, $data['reference'] ?? null);

        return response()->json(['data' => $this->presentSlip($payslip->refresh()->load(['items', 'user.staffProfile']))]);
    }

    public function removeItem(Request $request, PayslipItem $item): JsonResponse
    {
        $this->manage($request);
        $payslipId = $item->payslip_id;
        $this->payroll->removeItem($item);

        return response()->json(['data' => $this->presentSlip(Payslip::with(['items', 'user.staffProfile'])->findOrFail($payslipId))]);
    }

    public function removePayslip(Request $request, Payslip $payslip): Response
    {
        $this->manage($request);
        $this->payroll->removePayslip($payslip->load('run'));

        return response()->noContent();
    }

    /** One payslip, for printing: the managers, or its owner once it is paid. */
    public function payslip(Request $request, Payslip $payslip): JsonResponse
    {
        $user = $this->user($request);
        $payslip->load(['run', 'items', 'user.staffProfile']);
        abort_unless(PayrollAccess::views($user) || ($payslip->user_id === $user->id && $payslip->paid_on !== null), 403);

        return response()->json(['data' => $this->presentSlip($payslip) + ['month' => $payslip->run->month->format('Y-m'), 'run_status' => $payslip->run->status], 'currency' => $this->currency(), 'school' => (string) (tenant('name') ?? config('app.name'))]);
    }

    /** "My salary": the signed-in person's own current contract, its monthly items and how they are paid. */
    public function myContract(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $c = StaffContract::with('items')->where('user_id', $user->id)->orderByRaw("status = 'active' desc")->orderByDesc('starts_on')->orderByDesc('id')->first();
        $data = $c === null ? null : [
            'status' => $c->status, 'starts_on' => $c->starts_on->format('Y-m-d'), 'ends_on' => $c->ends_on?->format('Y-m-d'), 'base_salary' => $c->base_salary,
            'payment_method' => $c->payment_method, 'payment_details' => $c->payment_details,
            'items' => $c->items->map(fn ($i) => ['kind' => $i->kind, 'label' => $i->label, 'amount' => $i->amount])->values(),
        ];

        return response()->json(['data' => $data, 'currency' => $this->currency()]);
    }

    /** The signed-in person's own paid payslips. */
    public function mine(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $rows = Payslip::with('run')->where('user_id', $user->id)->whereNotNull('paid_on')->get()->sortByDesc(fn ($p) => $p->run->month)->values();

        return response()->json(['data' => $rows->map(fn (Payslip $p) => ['id' => $p->id, 'month' => $p->run->month->format('Y-m'), 'net' => $p->net, 'paid_on' => $p->paid_on->format('Y-m-d')])->values(), 'currency' => $this->currency()]);
    }

    /** @return array<string, mixed> */
    private function detail(PayrollRun $run): array
    {
        $run->load(['payslips.items', 'payslips.user.staffProfile', 'approver:id,name']);

        return $this->presentRun($run) + [
            'payslips' => $run->payslips->sortBy(fn ($p) => $p->user->name)->map(fn (Payslip $p) => $this->presentSlip($p))->values(),
            'totals' => [
                'base' => Money::amount($run->payslips->sum(fn ($p) => Money::cents($p->base_salary))), 'additions' => Money::amount($run->payslips->sum(fn ($p) => Money::cents($p->additions))),
                'deductions' => Money::amount($run->payslips->sum(fn ($p) => Money::cents($p->deductions))), 'net' => Money::amount($run->payslips->sum(fn ($p) => Money::cents($p->net))),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentRun(PayrollRun $r): array
    {
        return [
            'id' => $r->id, 'month' => $r->month->format('Y-m'), 'status' => $r->status, 'return_note' => $r->return_note,
            'submitted_at' => $r->submitted_at?->toIso8601String(), 'approved_at' => $r->approved_at?->toIso8601String(), 'approved_by' => $r->relationLoaded('approver') ? $r->approver?->name : null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentSlip(Payslip $p): array
    {
        return [
            'id' => $p->id, 'user' => ['id' => $p->user->id, 'name' => $p->user->name, 'staff_type' => $p->user->staffProfile?->staff_type->value],
            'base_salary' => $p->base_salary, 'additions' => $p->additions, 'deductions' => $p->deductions, 'net' => $p->net,
            'paid_on' => $p->paid_on?->format('Y-m-d'), 'payment_method' => $p->payment_method, 'payment_details' => $p->payment_details, 'reference' => $p->reference,
            'items' => $p->items->map(fn ($i) => ['id' => $i->id, 'kind' => $i->kind, 'label' => $i->label, 'amount' => $i->amount, 'from_contract' => $i->from_contract, 'auto_code' => $i->auto_code, 'detail' => $i->detail])->values(),
        ];
    }

    private function currency(): string
    {
        return (string) (DB::table('school_settings')->where('key', 'currency')->value('value') ?: 'JOD');
    }

    private function view(Request $request): User
    {
        $user = $this->user($request);
        abort_unless(PayrollAccess::views($user), 403, __('payroll.not_allowed'));

        return $user;
    }

    private function manage(Request $request): User
    {
        $user = $this->user($request);
        abort_unless(PayrollAccess::manages($user), 403, __('payroll.not_allowed'));

        return $user;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
