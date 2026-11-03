<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Services\FinanceAccess;
use App\Modules\Procurement\Models\ProcurementRequest;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
        $data = $request->validate(['supplier' => ['required', 'string', 'max:150'], 'actual_cost' => ['required', 'numeric', 'gt:0', 'max:10000000'], 'purchase_ref' => ['nullable', 'string', 'max:100'], 'purchased_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $procurement->update(['status' => 'purchased', 'supplier' => $data['supplier'], 'actual_cost' => $data['actual_cost'], 'purchase_ref' => $data['purchase_ref'] ?? null, 'purchased_by' => $user->id, 'purchased_at' => $data['purchased_on'] ?? now()]);
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
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
