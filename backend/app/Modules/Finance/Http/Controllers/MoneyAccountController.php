<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\AccountEntry;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\MoneyAccount;
use App\Modules\Finance\Services\FinanceAccess;
use App\Modules\Finance\Services\MoneyBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The school's cash boxes and bank accounts: balances, the book of each, transfers, corrections and matching with the bank. The accountant manages; the principal reads. */
class MoneyAccountController extends Controller
{
    public function __construct(private readonly MoneyBook $book) {}

    public function index(Request $request): JsonResponse
    {
        $this->view($request);
        $accounts = MoneyAccount::orderByDesc('is_active')->orderBy('id')->get();
        $rows = $accounts->map(fn (MoneyAccount $a) => $this->present($a))->values();

        return response()->json(['data' => $rows, 'total' => Money::amount((int) $accounts->where('is_active', true)->sum(fn (MoneyAccount $a) => $a->balanceCents())), 'currency' => $this->currency(), 'can_manage' => FinanceAccess::manages($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->manage($request);
        $account = MoneyAccount::create($this->data($request) + ['opened_on' => $request->input('opened_on') ?: now()->toDateString()]);

        return response()->json(['data' => $this->present($account)], 201);
    }

    public function update(Request $request, MoneyAccount $account): JsonResponse
    {
        $this->manage($request);
        $data = $this->data($request, true);
        if (array_key_exists('opening_balance', $data) && $account->entries()->whereNotNull('reconciled_at')->exists()) {
            throw ValidationException::withMessages(['opening_balance' => [__('finance.opening_locked')]]);
        }
        $account->update($data);

        return response()->json(['data' => $this->present($account->refresh())]);
    }

    /** An account with a book cannot be removed (the history stays): it is switched off instead. */
    public function destroy(Request $request, MoneyAccount $account): Response
    {
        $this->manage($request);
        if ($account->entries()->exists()) {
            throw ValidationException::withMessages(['account' => [__('finance.account_has_entries')]]);
        }
        $account->delete();

        return response()->noContent();
    }

    public function ledger(Request $request, MoneyAccount $account): JsonResponse
    {
        $this->view($request);
        $q = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $ledger = $this->book->ledger($account, $q['from'] ?? null, $q['to'] ?? null);

        return response()->json(['data' => $ledger + ['account' => $this->present($account), 'currency' => $this->currency()]]);
    }

    public function transfer(Request $request): JsonResponse
    {
        $user = $this->manage($request);
        $d = $request->validate([
            'from_id' => ['required', 'integer', 'exists:money_accounts,id'], 'to_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->book->transfer(MoneyAccount::findOrFail($d['from_id']), MoneyAccount::findOrFail($d['to_id']), $d['amount'], $d['date'], $d['note'] ?? null, $user);

        return response()->json(['data' => ['from' => $this->present(MoneyAccount::find($d['from_id'])), 'to' => $this->present(MoneyAccount::find($d['to_id']))]], 201);
    }

    public function adjust(Request $request, MoneyAccount $account): JsonResponse
    {
        $user = $this->manage($request);
        $d = $request->validate(['direction' => ['required', Rule::in(['in', 'out'])], 'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['required', 'string', 'max:255']]);
        $this->book->adjust($account, ($d['direction'] === 'out' ? '-' : '').$d['amount'], $d['date'], $d['note'], $user);

        return response()->json(['data' => $this->present($account->refresh())], 201);
    }

    public function reconciliation(Request $request, MoneyAccount $account): JsonResponse
    {
        $this->view($request);

        return response()->json(['data' => $this->book->toReconcile($account) + ['account' => $this->present($account), 'currency' => $this->currency()]]);
    }

    public function reconcile(Request $request, MoneyAccount $account): JsonResponse
    {
        $user = $this->manage($request);
        $d = $request->validate(['statement_date' => ['required', 'date_format:Y-m-d'], 'statement_balance' => ['required', 'numeric'], 'entry_ids' => ['present', 'array', 'max:5000'], 'entry_ids.*' => ['integer']]);
        $this->book->reconcile($account, $d['statement_date'], $d['statement_balance'], $d['entry_ids'], $user);

        return response()->json(['data' => $this->book->toReconcile($account)]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:120'], 'type' => [$req, Rule::in(MoneyAccount::TYPES)], 'opening_balance' => ['sometimes', 'numeric', 'min:-100000000', 'max:100000000'],
            'bank_name' => ['nullable', 'string', 'max:120'], 'iban' => ['nullable', 'string', 'max:60'], 'is_active' => ['sometimes', 'boolean'], 'opened_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(MoneyAccount $a): array
    {
        return ['id' => $a->id, 'name' => $a->name, 'type' => $a->type, 'bank_name' => $a->bank_name, 'iban' => $a->iban, 'is_active' => $a->is_active, 'opening_balance' => $a->opening_balance, 'opened_on' => $a->opened_on->format('Y-m-d'), 'balance' => Money::amount($a->balanceCents()), 'has_entries' => $a->entries()->exists(),
            'unmatched' => AccountEntry::where('money_account_id', $a->id)->whereNull('reconciled_at')->count()];
    }

    private function view(Request $request): void
    {
        abort_unless(FinanceAccess::views($request->user()), 403, __('finance.not_allowed'));
    }

    private function manage(Request $request): \App\Models\User
    {
        abort_unless(FinanceAccess::manages($request->user()), 403, __('finance.not_allowed'));

        return $request->user();
    }

    private function currency(): string
    {
        return (string) (DB::table('school_settings')->where('key', 'currency')->value('value') ?: 'JOD');
    }
}
