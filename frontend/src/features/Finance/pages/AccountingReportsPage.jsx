import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import { api } from '../../../api/client'
import ExportButton from '../../../components/shared/ExportButton'
import { DateInput } from '../../../components/shared/DateInput'

const REPORTS = ['trial-balance', 'income-statement', 'balance-sheet', 'account-ledger', 'receivables-ageing']
const MONEY_COLS = ['debit', 'credit', 'amount', 'balance', 'current', 'd30', 'd60', 'd90', 'over90', 'total']
const today = () => new Date().toISOString().slice(0, 10)

// Trial balance, income statement, balance sheet, account ledger and receivables ageing, read from the journal.
export default function AccountingReportsPage() {
  const { t, i18n } = useTranslation()
  const [report, setReport] = useState('trial-balance')
  const [range, setRange] = useState({ from: `${new Date().getFullYear()}-01-01`, to: today() })
  const [accountId, setAccountId] = useState('')
  const needsAccount = report === 'account-ledger'
  const params = { from: range.from || undefined, to: range.to || undefined, ...(needsAccount ? { account_id: accountId || undefined } : {}) }
  const accounts = useQuery({ queryKey: ['finance', 'ledger-accounts'], queryFn: async () => (await api.get('/finance/ledger-accounts')).data })
  const { data, isError } = useQuery({
    queryKey: ['finance', 'accounting-report', report, params],
    enabled: !needsAccount || Boolean(accountId),
    queryFn: async () => (await api.get(`/finance/accounting-reports/${report}`, { params })).data.data,
  })
  const money = (v) => Number(v).toLocaleString(i18n.language === 'ar' ? 'ar-JO-u-nu-latn' : 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  const cell = (col, v) => (MONEY_COLS.includes(col) ? <span dir="ltr">{money(v)}</span> : col === 'type' ? t(`journal.types.${v}`) : v)
  const tab = (k) => `rounded-full px-3 py-1 text-sm ${report === k ? 'bg-brand text-white' : 'border text-brand'}`
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2 print:hidden">
        <h1 className="text-xl font-bold">{t('acctReports.title')}</h1>
        <div className="flex gap-2">
          <button type="button" className="inline-flex items-center gap-2 rounded border px-3 py-1.5 text-sm text-brand" onClick={() => window.print()}><Printer size={15} aria-hidden />{t('acctReports.print')}</button>
          {(!needsAccount || accountId) && <ExportButton path={`/finance/accounting-reports/${report}`} params={{ ...params, format: 'xlsx' }} fallbackName={`${report}.xlsx`} />}
        </div>
      </div>
      <div className="flex flex-wrap gap-2 print:hidden">{REPORTS.map((k) => <button key={k} type="button" className={tab(k)} onClick={() => setReport(k)}>{t(`acctReports.names.${k}`)}</button>)}</div>
      <div className="flex flex-wrap items-end gap-3 rounded-lg border bg-white p-3 text-sm print:hidden">
        {['income-statement', 'account-ledger'].includes(report) && <label>{t('money.fromDate')}<DateInput className="mt-1 block rounded border p-1.5" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /></label>}
        <label>{['trial-balance', 'balance-sheet', 'receivables-ageing'].includes(report) ? t('acctReports.asOf') : t('money.toDate')}<DateInput className="mt-1 block rounded border p-1.5" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></label>
        {needsAccount && (
          <label>{t('journal.account')}
            <select className="mt-1 block rounded border p-1.5" value={accountId} onChange={(e) => setAccountId(e.target.value)}>
              <option value="">—</option>
              {(accounts.data?.data ?? []).map((a) => <option key={a.id} value={a.id}>{a.code} · {a[`name_${i18n.language}`] || a.name_ar}</option>)}
            </select>
          </label>
        )}
      </div>
      <h2 className="hidden text-lg font-bold print:block">{t(`acctReports.names.${report}`)} · {range.to}</h2>
      {isError ? <p role="alert" className="text-red-700">{t('admin.actions.error')}</p> : needsAccount && !accountId ? <p className="text-slate-500">{t('acctReports.pickAccount')}</p> : !data ? <p className="text-slate-500">{t('common.loading')}</p> : (
        <>
          {data.balanced === false && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{t('acctReports.notBalanced')}</p>}
          <div className="overflow-x-auto rounded-lg border bg-white">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-start"><tr>{data.columns.map((c) => <th key={c} className="p-2 text-start">{t(`acctReports.cols.${c}`)}</th>)}</tr></thead>
              <tbody>
                {data.rows.length === 0 && <tr><td className="p-4 text-center text-slate-500" colSpan={data.columns.length}>{t('acctReports.none')}</td></tr>}
                {data.rows.map((row, i) => <tr key={i} className="border-t">{row.map((v, j) => <td key={j} className="p-2">{cell(data.columns[j], v)}</td>)}</tr>)}
              </tbody>
            </table>
          </div>
          <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {Object.entries(data.totals).map(([k, v]) => <div key={k} className="rounded-lg border bg-white p-3"><dt className="text-sm text-slate-500">{t(`acctReports.totals.${k}`)}</dt><dd className="text-lg font-bold" dir="ltr">{money(v)}</dd></div>)}
          </dl>
        </>
      )}
    </section>
  )
}
