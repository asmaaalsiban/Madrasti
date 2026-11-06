<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\ExpensePayment;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\MoneyAccount;
use App\Modules\Finance\Models\Supplier;
use App\Modules\Finance\Services\Expenses;
use App\Modules\Finance\Services\FinanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** What the school spends: bills, the payments made on them, suppliers and categories. The accountant records; the principal reads. */
class ExpenseController extends Controller
{
    public function __construct(private readonly Expenses $expenses) {}

    /** The bills (filtered), with the totals of what is listed, and what the forms need (categories, suppliers, accounts). */
    public function index(Request $request): JsonResponse
    {
        $this->view($request);
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'category_id' => ['nullable', 'integer'], 'supplier_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['unpaid', 'partial', 'paid', 'overdue', 'open'])], 'search' => ['nullable', 'string', 'max:100']]);
        $rows = Expense::with(['supplier:id,name', 'category', 'payments.account:id,name'])
            ->when($q['from'] ?? null, fn ($b, $v) => $b->whereDate('expense_date', '>=', $v))->when($q['to'] ?? null, fn ($b, $v) => $b->whereDate('expense_date', '<=', $v))
            ->when($q['category_id'] ?? null, fn ($b, $v) => $b->where('expense_category_id', $v))->when($q['supplier_id'] ?? null, fn ($b, $v) => $b->where('supplier_id', $v))
            ->when($q['search'] ?? null, fn ($b, $v) => $b->where(fn ($w) => $w->where('description', 'like', "%$v%")->orWhere('reference', 'like', "%$v%")))
            ->orderByDesc('expense_date')->orderByDesc('id')->limit(500)->get();
        if (! empty($q['status'])) {
            $rows = $rows->filter(fn (Expense $e) => $q['status'] === 'open' ? $e->remainingCents() > 0 : $e->status() === $q['status'])->values();
        }
        $total = (int) $rows->sum(fn (Expense $e) => Money::cents($e->amount));
        $paid = (int) $rows->sum(fn (Expense $e) => $e->paidCents());

        return response()->json([
            'data' => $rows->map(fn (Expense $e) => $this->present($e))->values(),
            'summary' => [
                'total' => Money::amount($total), 'paid' => Money::amount($paid), 'remaining' => Money::amount($total - $paid),
                'overdue' => Money::amount((int) $rows->filter(fn (Expense $e) => $e->status() === 'overdue')->sum(fn (Expense $e) => $e->remainingCents())),
                'by_category' => $rows->groupBy('expense_category_id')->map(fn ($g) => ['category' => $this->category($g->first()->category), 'total' => Money::amount((int) $g->sum(fn (Expense $e) => Money::cents($e->amount)))])->sortByDesc(fn ($r) => (float) $r['total'])->values(),
            ],
            'categories' => ExpenseCategory::where('is_active', true)->orderBy('id')->get()->map(fn ($c) => $this->category($c))->values(),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name'])->values(),
            'accounts' => MoneyAccount::where('is_active', true)->orderBy('id')->get(['id', 'name', 'type'])->values(),
            'methods' => ExpensePayment::METHODS, 'tax' => ['enabled' => app(\App\Modules\Finance\Services\Tax::class)->settings()['enabled'], 'name' => app(\App\Modules\Finance\Services\Tax::class)->settings()['name']], 'currency' => $this->currency(), 'can_manage' => FinanceAccess::manages($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->manage($request);
        $data = $this->billData($request);
        $payNow = $request->validate(['pay_now' => ['nullable', 'array'], 'pay_now.money_account_id' => ['required_with:pay_now', 'integer'], 'pay_now.method' => ['required_with:pay_now', Rule::in(ExpensePayment::METHODS)],
            'pay_now.paid_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'pay_now.reference' => ['nullable', 'string', 'max:80'], 'pay_now.note' => ['nullable', 'string', 'max:255']])['pay_now'] ?? null;

        return response()->json(['data' => $this->present($this->expenses->create($data, $user, $payNow)->load(['supplier:id,name', 'category', 'payments.account:id,name']))], 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $this->manage($request);
        $expense = $this->expenses->update($expense, $this->billData($request, true));

        return response()->json(['data' => $this->present($expense->load(['supplier:id,name', 'category', 'payments.account:id,name']))]);
    }

    public function destroy(Request $request, Expense $expense): Response
    {
        $this->manage($request);
        $this->expenses->delete($expense);

        return response()->noContent();
    }

    public function pay(Request $request, Expense $expense): JsonResponse
    {
        $user = $this->manage($request);
        $d = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'money_account_id' => ['required', 'integer'], 'method' => ['required', Rule::in(ExpensePayment::METHODS)],
            'paid_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'reference' => ['nullable', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->expenses->pay($expense, $d['amount'], (int) $d['money_account_id'], $d['method'], $d['paid_on'] ?? now()->toDateString(), $d['reference'] ?? null, $d['note'] ?? null, $user);

        return response()->json(['data' => $this->present($expense->refresh()->load(['supplier:id,name', 'category', 'payments.account:id,name']))], 201);
    }

    public function voidPayment(Request $request, ExpensePayment $payment): JsonResponse
    {
        $this->manage($request);
        $expense = $payment->expense;
        $this->expenses->removePayment($payment);

        return response()->json(['data' => $this->present($expense->refresh()->load(['supplier:id,name', 'category', 'payments.account:id,name']))]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $this->view($request);
        $rows = Supplier::with('expenses.payments')->orderByDesc('is_active')->orderBy('name')->get()->map(fn (Supplier $s) => [
            'id' => $s->id, 'name' => $s->name, 'phone' => $s->phone, 'email' => $s->email, 'notes' => $s->notes, 'is_active' => $s->is_active, 'bills' => $s->expenses->count(),
            'owed' => Money::amount((int) $s->expenses->sum(fn (Expense $e) => $e->remainingCents())), 'bought' => Money::amount((int) $s->expenses->sum(fn ($e) => Money::cents($e->amount))),
        ]);

        return response()->json(['data' => $rows->values(), 'currency' => $this->currency(), 'can_manage' => FinanceAccess::manages($request->user())]);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $this->manage($request);

        return response()->json(['data' => Supplier::create($this->supplierData($request))], 201);
    }

    public function updateSupplier(Request $request, Supplier $supplier): JsonResponse
    {
        $this->manage($request);
        $supplier->update($this->supplierData($request, true));

        return response()->json(['data' => $supplier->refresh()]);
    }

    public function destroySupplier(Request $request, Supplier $supplier): Response
    {
        $this->manage($request);
        if ($supplier->expenses()->exists()) {
            throw ValidationException::withMessages(['supplier' => [__('finance.supplier_has_bills')]]);
        }
        $supplier->delete();

        return response()->noContent();
    }

    public function categories(Request $request): JsonResponse
    {
        $this->view($request);

        return response()->json(['data' => ExpenseCategory::withCount('expenses')->orderBy('id')->get()->map(fn ($c) => $this->category($c) + ['is_active' => $c->is_active, 'bills' => $c->expenses_count])->values(), 'can_manage' => FinanceAccess::manages($request->user())]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->manage($request);

        return response()->json(['data' => ExpenseCategory::create($request->validate(['name_ar' => ['required', 'string', 'max:80'], 'name_en' => ['required', 'string', 'max:80']]))], 201);
    }

    public function updateCategory(Request $request, ExpenseCategory $category): JsonResponse
    {
        $this->manage($request);
        $category->update($request->validate(['name_ar' => ['sometimes', 'string', 'max:80'], 'name_en' => ['sometimes', 'string', 'max:80'], 'is_active' => ['sometimes', 'boolean']]));

        return response()->json(['data' => $category->refresh()]);
    }

    public function destroyCategory(Request $request, ExpenseCategory $category): Response
    {
        $this->manage($request);
        if ($category->expenses()->exists()) {
            throw ValidationException::withMessages(['category' => [__('finance.category_in_use')]]);
        }
        $category->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function billData(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'expense_date' => [$req, 'date_format:Y-m-d', 'before_or_equal:today'], 'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'], 'expense_category_id' => [$req, 'integer', 'exists:expense_categories,id'],
            'description' => [$req, 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:80'], 'amount' => [$req, 'numeric', 'gt:0', 'max:100000000'], 'tax_amount' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'due_date' => ['nullable', 'date_format:Y-m-d'], 'procurement_request_id' => ['nullable', 'integer', 'exists:procurement_requests,id'],
        ]);
    }

    /** @return array<string, mixed> */
    private function supplierData(Request $request, bool $partial = false): array
    {
        return $request->validate(['name' => [$partial ? 'sometimes' : 'required', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:150'], 'notes' => ['nullable', 'string', 'max:500'], 'is_active' => ['sometimes', 'boolean']]);
    }

    /** @return array<string, mixed> */
    private function category(?ExpenseCategory $c): array
    {
        return ['id' => $c?->id, 'name_ar' => $c?->name_ar, 'name_en' => $c?->name_en];
    }

    /** @return array<string, mixed> */
    private function present(Expense $e): array
    {
        return [
            'id' => $e->id, 'date' => $e->expense_date->format('Y-m-d'), 'supplier' => $e->supplier ? ['id' => $e->supplier->id, 'name' => $e->supplier->name] : null, 'category' => $this->category($e->category),
            'description' => $e->description, 'reference' => $e->reference, 'amount' => $e->amount, 'tax_amount' => $e->tax_amount, 'due_date' => $e->due_date?->format('Y-m-d'), 'procurement_request_id' => $e->procurement_request_id,
            'paid' => Money::amount($e->paidCents()), 'remaining' => Money::amount($e->remainingCents()), 'status' => $e->status(),
            'payments' => $e->payments->map(fn (ExpensePayment $p) => ['id' => $p->id, 'amount' => $p->amount, 'paid_on' => $p->paid_on->format('Y-m-d'), 'method' => $p->method, 'account' => $p->account ? ['id' => $p->account->id, 'name' => $p->account->name] : null, 'reference' => $p->reference, 'note' => $p->note])->values(),
        ];
    }

    private function view(Request $request): void
    {
        abort_unless(FinanceAccess::views($request->user()), 403, __('finance.not_allowed'));
    }

    private function manage(Request $request): User
    {
        abort_unless(FinanceAccess::manages($request->user()), 403, __('finance.not_allowed'));

        return $request->user();
    }

    private function currency(): string
    {
        return (string) (DB::table('school_settings')->where('key', 'currency')->value('value') ?: 'JOD');
    }
}
