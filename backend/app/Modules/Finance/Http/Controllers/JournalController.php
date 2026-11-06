<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\LedgerAccount;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Services\FinanceAccess;
use App\Modules\Finance\Services\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The chart of accounts, the journal and the books lock. The accountant manages; the principal reads. */
class JournalController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    public function accounts(Request $request): JsonResponse
    {
        $this->view($request);
        $rows = LedgerAccount::withCount('lines')->orderBy('code')->get()->map(fn (LedgerAccount $a) => [
            'id' => $a->id, 'code' => $a->code, 'name_ar' => $a->name_ar, 'name_en' => $a->name_en, 'type' => $a->type,
            'is_system' => $a->is_system, 'is_active' => $a->is_active, 'lines' => $a->lines_count,
        ]);

        return response()->json(['data' => $rows, 'types' => LedgerAccount::TYPES, 'can_manage' => FinanceAccess::manages($request->user())]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $this->manage($request);
        $a = LedgerAccount::create($this->accountData($request));

        return response()->json(['data' => $a->only(['id', 'code', 'name_ar', 'name_en', 'type'])], 201);
    }

    public function updateAccount(Request $request, LedgerAccount $account): JsonResponse
    {
        $this->manage($request);
        $data = $this->accountData($request, $account);
        if ($account->lines()->exists() || $account->is_system) {
            unset($data['type'], $data['code']); // the meaning of a used account never changes
        }
        $account->update($data);

        return response()->json(['data' => $account->only(['id', 'code', 'name_ar', 'name_en', 'type', 'is_active'])]);
    }

    public function destroyAccount(Request $request, LedgerAccount $account): Response
    {
        $this->manage($request);
        if ($account->is_system || $account->lines()->exists()) {
            throw ValidationException::withMessages(['account' => [__('finance.ledger_account_used')]]);
        }
        $account->delete();

        return response()->noContent();
    }

    public function entries(Request $request): JsonResponse
    {
        $this->view($request);
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $q = JournalEntry::with('lines.account')->orderByDesc('entry_date')->orderByDesc('id');
        if ($request->filled('from')) {
            $q->where('entry_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->where('entry_date', '<=', $request->input('to'));
        }
        $rows = $q->limit(200)->get()->map(fn (JournalEntry $e) => [
            'id' => $e->id, 'date' => $e->entry_date->toDateString(), 'memo' => $e->memo, 'manual' => $e->source_type === null,
            'total' => Money::amount((int) $e->lines->sum(fn ($l) => Money::cents($l->debit))),
            'lines' => $e->lines->map(fn ($l) => ['account_id' => $l->ledger_account_id, 'code' => $l->account->code, 'name_ar' => $l->account->name_ar, 'name_en' => $l->account->name_en, 'debit' => $l->debit, 'credit' => $l->credit, 'note' => $l->note])->values(),
        ]);

        return response()->json(['data' => $rows, 'locked_until' => $this->journal->lockedUntil(), 'can_manage' => FinanceAccess::manages($request->user())]);
    }

    public function storeEntry(Request $request): JsonResponse
    {
        $user = $this->manage($request);
        $data = $request->validate([
            'entry_date' => ['required', 'date_format:Y-m-d'], 'memo' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.ledger_account_id' => ['required', 'integer', Rule::exists('ledger_accounts', 'id')->where('is_active', true)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:999999999'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ]);
        $entry = $this->journal->post($data['entry_date'], $data['memo'], $data['lines'], $user);

        return response()->json(['data' => ['id' => $entry->id]], 201);
    }

    /** Only a hand-made entry can be removed, and only while its date is open. */
    public function destroyEntry(Request $request, JournalEntry $entry): Response
    {
        $this->manage($request);
        if ($entry->source_type !== null) {
            throw ValidationException::withMessages(['entry' => [__('finance.entry_is_automatic')]]);
        }
        $this->journal->assertOpen($entry->entry_date->toDateString());
        $entry->delete();

        return response()->noContent();
    }

    public function lock(Request $request): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['locked_until' => ['nullable', 'date_format:Y-m-d']]);
        $this->journal->lock($data['locked_until'] ?? null);

        return response()->json(['locked_until' => $this->journal->lockedUntil()]);
    }

    /** @return array<string, mixed> */
    private function accountData(Request $request, ?LedgerAccount $account = null): array
    {
        return $request->validate([
            'code' => [$account ? 'sometimes' : 'required', 'string', 'max:20', Rule::unique('ledger_accounts', 'code')->ignore($account?->id)],
            'name_ar' => [$account ? 'sometimes' : 'required', 'string', 'max:120'], 'name_en' => [$account ? 'sometimes' : 'required', 'string', 'max:120'],
            'type' => [$account ? 'sometimes' : 'required', Rule::in(LedgerAccount::TYPES)], 'is_active' => ['sometimes', 'boolean'],
        ]);
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
}
