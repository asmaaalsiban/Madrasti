<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\ExpensePayment;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Services\Expenses;
use App\Modules\Finance\Services\FinanceAccess;
use App\Modules\Procurement\Models\ProcurementRequest;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The purchase board. Any member of staff asks; the principal approves or refuses; the accountant records the purchase; the
 * requester (or the principal) confirms it arrived. Staff see their own requests, the principal and the accountant see all.
 */
class ProcurementController extends Controller
{
    public function __construct(private readonly Notifier $notifier) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $rows = ProcurementRequest::with(['requester:id,name', 'reviewer:id,name', 'buyer:id,name'])->when(! $this->sees($user), fn ($q) => $q->where('requested_by', $user->id))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->orderByDesc('id')->get();
        $total = fn ($rs, string $col) => Money::amount($rs->sum(fn ($r) => Money::cents($r->{$col} ?? 0)));

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->present($r, $user))->values(), 'currency' => $this->currency(), 'can_review' => $user->hasRole(Role::Admin), 'can_buy' => FinanceAccess::manages($user),
            'summary' => collect(['pending', 'approved', 'rejected', 'purchased', 'received'])->mapWithKeys(fn ($s) => [$s => $rows->where('status', $s)->count()])->all()
                + ['to_spend' => $total($rows->where('status', 'approved'), 'estimated_cost'), 'spent' => $total($rows->whereIn('status', ['purchased', 'received']), 'actual_cost')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $r = ProcurementRequest::create($this->rules($request) + ['requested_by' => $user->id, 'status' => 'pending']);
        $this->notifier->toUsers(User::where('role', Role::Admin)->where('is_active', true)->where('id', '!=', $user->id)->pluck('id'), 'procurement_new', ['name' => $user->name, 'title' => $r->title], '/admin/procurement');

        return response()->json(['data' => $this->present($r->load('requester:id,name'), $user)], 201);
    }

    /** The requester, while nobody has decided yet (the principal can also edit). */
    public function update(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(($procurement->requested_by === $user->id && $procurement->status === 'pending') || $user->hasRole(Role::Admin), 403);
        $procurement->update($this->rules($request));

        return response()->json(['data' => $this->present($procurement->refresh()->load(['requester:id,name', 'reviewer:id,name', 'buyer:id,name']), $user)]);
    }

    /** A request nobody decided yet, or one that was refused: its requester or the principal removes it. */
    public function destroy(Request $request, ProcurementRequest $procurement): Response
    {
        $user = $this->user($request);
        abort_unless(in_array($procurement->status, ['pending', 'rejected'], true) && ($procurement->requested_by === $user->id || $user->hasRole(Role::Admin)), 403);
        $procurement->delete();

        return response()->noContent();
    }

    public function approve(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        return $this->decide($request, $procurement, 'approved');
    }

    public function reject(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        return $this->decide($request, $procurement, 'rejected');
    }

    /** The accountant records the purchase of an approved request: where from, what it really cost, the invoice. */
    public function purchase(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(FinanceAccess::manages($user), 403, __('procurement.not_allowed'));
        $this->expect($procurement, 'approved');
        $data = $request->validate([
            'supplier' => ['required', 'string', 'max:150'], 'actual_cost' => ['required', 'numeric', 'gt:0', 'max:10000000'], 'purchase_ref' => ['nullable', 'string', 'max:100'], 'purchased_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            // The purchase is also recorded as a bill (see below): its category, due date, and optionally the account it was paid from right now.
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'], 'due_date' => ['nullable', 'date_format:Y-m-d'],
            'pay_now' => ['nullable', 'array'], 'pay_now.money_account_id' => ['required_with:pay_now', 'integer'], 'pay_now.method' => ['required_with:pay_now', Rule::in(ExpensePayment::METHODS)], 'pay_now.reference' => ['nullable', 'string', 'max:80'],
        ]);
        // One button, two records: the purchase and its bill (supplier, category, invoice number, and the payment when it was paid at once). All or nothing.
        DB::transaction(function () use ($procurement, $data, $user): void {
            $procurement->update(['status' => 'purchased', 'supplier' => $data['supplier'], 'actual_cost' => $data['actual_cost'], 'purchase_ref' => $data['purchase_ref'] ?? null, 'purchased_by' => $user->id, 'purchased_at' => $data['purchased_on'] ?? now()]);
            $supplier = Supplier::whereRaw('lower(name) = ?', [mb_strtolower(trim($data['supplier']))])->first() ?? Supplier::create(['name' => trim($data['supplier'])]);
            $category = ExpenseCategory::find($data['expense_category_id'] ?? null) ?? ExpenseCategory::where('name_en', 'Supplies')->where('is_active', true)->first() ?? ExpenseCategory::where('is_active', true)->orderBy('id')->firstOrFail();
            $when = $data['purchased_on'] ?? now()->toDateString();
            $payNow = isset($data['pay_now']) ? ['money_account_id' => (int) $data['pay_now']['money_account_id'], 'method' => $data['pay_now']['method'], 'paid_on' => $when, 'reference' => $data['pay_now']['reference'] ?? null] : null;
            app(Expenses::class)->create(['expense_date' => $when, 'supplier_id' => $supplier->id, 'expense_category_id' => $category->id, 'description' => $procurement->title, 'reference' => $data['purchase_ref'] ?? null, 'amount' => $data['actual_cost'], 'due_date' => $data['due_date'] ?? null, 'procurement_request_id' => $procurement->id], $user, $payNow);
        });
        $this->notifier->toUsers([$procurement->requested_by], 'procurement_purchased', ['title' => $procurement->title], '/teacher/procurement');

        return response()->json(['data' => $this->present($procurement->refresh()->load(['requester:id,name', 'reviewer:id,name', 'buyer:id,name']), $user)]);
    }

    /** It arrived: the requester, the principal or the accountant confirms. */
    public function receive(Request $request, ProcurementRequest $procurement): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($procurement->requested_by === $user->id || $user->hasRole(Role::Admin) || FinanceAccess::manages($user), 403, __('procurement.not_allowed'));
        $this->expect($procurement, 'purchased');
        $procurement->update(['status' => 'received', 'received_at' => now()]);

        return response()->json(['data' => $this->present($procurement->refresh()->load(['requester:id,name', 'reviewer:id,name', 'buyer:id,name']), $user)]);
    }

    private function decide(Request $request, ProcurementRequest $procurement, string $to): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasRole(Role::Admin), 403, __('procurement.not_allowed'));
        $this->expect($procurement, 'pending');
        $data = $request->validate(['note' => [$to === 'rejected' ? 'required' : 'nullable', 'string', 'max:500']]);
        $procurement->update(['status' => $to, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null]);
        $this->notifier->toUsers([$procurement->requested_by], 'procurement_'.$to, ['title' => $procurement->title, 'note' => $data['note'] ?? ''], '/teacher/procurement');
        if ($to === 'approved') {
            // The accountant has something to buy.
            $this->notifier->toUsers(User::where('role', Role::Teacher)->where('is_active', true)->whereHas('staffProfile', fn ($q) => $q->where('staff_type', StaffType::Accountant->value))->pluck('id'), 'procurement_to_buy', ['title' => $procurement->title], '/teacher/procurement');
        }

        return response()->json(['data' => $this->present($procurement->refresh()->load(['requester:id,name', 'reviewer:id,name', 'buyer:id,name']), $user)]);
    }

    private function expect(ProcurementRequest $r, string $status): void
    {
        if ($r->status !== $status) {
            throw ValidationException::withMessages(['status' => [__('procurement.wrong_state')]]);
        }
    }

    /** @return array<string, mixed> */
    private function rules(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'], 'quantity' => ['required', 'integer', 'between:1,10000'], 'category' => ['required', 'in:'.implode(',', ProcurementRequest::CATEGORIES)],
            'priority' => ['required', 'in:'.implode(',', ProcurementRequest::PRIORITIES)], 'estimated_cost' => ['nullable', 'numeric', 'gte:0', 'max:10000000'], 'needed_by' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    private function sees(User $user): bool
    {
        return $user->hasRole(Role::Admin) || FinanceAccess::manages($user);
    }

    private function currency(): string
    {
        return (string) (DB::table('school_settings')->where('key', 'currency')->value('value') ?: 'JOD');
    }

    /** @return array<string, mixed> */
    private function present(ProcurementRequest $r, User $user): array
    {
        return [
            'id' => $r->id, 'title' => $r->title, 'description' => $r->description, 'quantity' => $r->quantity, 'category' => $r->category, 'priority' => $r->priority, 'status' => $r->status,
            'estimated_cost' => $r->estimated_cost, 'needed_by' => $r->needed_by?->toDateString(), 'created_at' => $r->created_at->toIso8601String(),
            'requester' => ['id' => $r->requester->id, 'name' => $r->requester->name], 'review_note' => $r->review_note, 'reviewed_by' => $r->relationLoaded('reviewer') ? $r->reviewer?->name : null,
            'supplier' => $r->supplier, 'actual_cost' => $r->actual_cost, 'purchase_ref' => $r->purchase_ref, 'purchased_at' => $r->purchased_at?->toDateString(), 'received_at' => $r->received_at?->toDateString(),
            'mine' => $r->requested_by === $user->id,
            // The bill recorded when it was bought (the accountant opens it in Expenses).
            'expense_id' => in_array($r->status, ['purchased', 'received'], true) ? Expense::where('procurement_request_id', $r->id)->value('id') : null,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
