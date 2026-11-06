<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\LedgerAccount;
use App\Modules\Finance\Services\AccountingReports;
use App\Modules\Finance\Services\FinanceAccess;
use App\Shared\Export\XlsxExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The accounting reports, on screen or as an Excel file. The principal and the accountant read them. */
class AccountingReportController extends Controller
{
    public const REPORTS = ['trial-balance', 'income-statement', 'balance-sheet', 'account-ledger', 'receivables-ageing', 'tax-summary'];

    public function __construct(private readonly AccountingReports $reports) {}

    public function show(Request $request, string $report, XlsxExport $xlsx): JsonResponse|BinaryFileResponse
    {
        abort_unless(FinanceAccess::views($request->user()), 403, __('finance.not_allowed'));
        abort_unless(in_array($report, self::REPORTS, true), 404);
        $d = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account_id' => [$report === 'account-ledger' ? 'required' : 'nullable', 'integer', 'exists:ledger_accounts,id'], 'format' => ['nullable', 'in:xlsx'],
        ]);
        $to = $d['to'] ?? now()->toDateString();
        $from = $d['from'] ?? substr($to, 0, 4).'-01-01';
        $data = match ($report) {
            'trial-balance' => $this->reports->trialBalance($to),
            'income-statement' => $this->reports->incomeStatement($from, $to),
            'balance-sheet' => $this->reports->balanceSheet($to),
            'account-ledger' => $this->reports->accountLedger(LedgerAccount::findOrFail($d['account_id']), $d['from'] ?? null, $d['to'] ?? null),
            'receivables-ageing' => $this->reports->receivablesAgeing($to),
            'tax-summary' => $this->reports->taxSummary($from, $to),
        };
        $data += ['report' => $report, 'from' => $from, 'to' => $to];

        if (($d['format'] ?? null) === 'xlsx') {
            return $xlsx->download($report.'-'.$to, __('finance.rpt.names.'.$report), array_map(fn (string $c) => __('finance.rpt.cols.'.$c), $data['columns']), $data['rows']);
        }

        return response()->json(['data' => $data]);
    }
}
