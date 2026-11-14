import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Check } from 'lucide-react'
import { api } from '../../../api/client'
import { DateInput } from '../../../components/shared/DateInput'
import Money from '../components/Money'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const today = () => new Date().toISOString().slice(0, 10)
const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const kindLabel = (t, e) => t(`money.kinds.${e.kind}`)

function Ledger({ id }) {
  const { t } = useTranslation()
  const [range, setRange] = useState({ from: '', to: '' })
  const { data } = useQuery({ queryKey: ['finance', 'ledger', id, range], queryFn: async () => (await api.get(`/finance/money-accounts/${id}/ledger`, { params: { from: range.from || undefined, to: range.to || undefined } })).data.data })
  const c = data?.currency
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-end gap-3 rounded-lg border bg-white p-3">
        <label className="text-sm">{t('money.fromDate')}<DateInput className="mt-1 block rounded border p-1.5" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /></label>
        <label className="text-sm">{t('money.toDate')}<DateInput className="mt-1 block rounded border p-1.5" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></label>
        {(range.from || range.to) && <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5 text-sm" onClick={() => setRange({ from: '', to: '' })}>{t('money.clear')}</InteractiveHoverButton>}
      </div>
      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="w-full text-start text-sm">
            <thead className="bg-slate-50"><tr>{['date', 'what', 'in', 'out', 'balance'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`money.cols.${k}`)}</th>)}<th><span className="sr-only">{t('money.matched')}</span></th></tr></thead>
            <tbody>
              <tr className="border-t bg-slate-50/60"><td className="p-2" /><td className="p-2 font-medium">{t('money.forward')}</td><td className="p-2" /><td className="p-2" /><td className="p-2 font-bold"><Money value={data.forward} currency={c} /></td><td /></tr>
              {data.rows.map((r) => (
                <tr key={r.id} className="border-t">
                  <td className="p-2" dir="ltr">{r.date}</td>
                  <td className="p-2">{kindLabel(t, r)}{r.description && <span className="text-slate-500"> · <bdi>{r.description}</bdi></span>}</td>
                  <td className="p-2 text-green-700">{Number(r.amount) > 0 ? <Money value={r.amount} currency={c} /> : ''}</td>
                  <td className="p-2 text-red-700">{Number(r.amount) < 0 ? <Money value={String(Math.abs(Number(r.amount)).toFixed(2))} currency={c} /> : ''}</td>
                  <td className="p-2"><Money value={r.balance} currency={c} /></td>
                  <td className="p-2">{r.reconciled && <Check size={16} className="text-green-700" aria-label={t('money.matched')} />}</td>
                </tr>
              ))}
              <tr className="border-t bg-slate-50"><td className="p-2" /><td className="p-2 font-bold">{t('money.closing')}</td><td className="p-2" /><td className="p-2" /><td className="p-2 font-extrabold"><Money value={data.closing} currency={c} /></td><td /></tr>
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

// Matching the book with the bank's statement: tick the lines that appear on it; it can be finished only when the balances agree.
function Reconcile({ id, canManage }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [picked, setPicked] = useState(null) // Set of ids; null = all open lines
  const [statement, setStatement] = useState({ date: today(), balance: '' })
  const [message, setMessage] = useState(null)
  const { data } = useQuery({ queryKey: ['finance', 'reconciliation', id], queryFn: async () => (await api.get(`/finance/money-accounts/${id}/reconciliation`)).data.data })
  const c = data?.currency
  const entries = data?.entries ?? []
  const chosen = picked ?? new Set(entries.map((e) => e.id))
  const toggle = (eid) => { const next = new Set(chosen); if (next.has(eid)) next.delete(eid); else next.add(eid); setPicked(next) }
  const sum = entries.filter((e) => chosen.has(e.id)).reduce((s, e) => s + Number(e.amount), 0)
  const cleared = Number(data?.cleared_balance ?? 0) + sum
  const difference = statement.balance === '' ? null : Number(statement.balance) - cleared
  const finish = useMutation({
    mutationFn: () => api.post(`/finance/money-accounts/${id}/reconciliation`, { statement_date: statement.date, statement_balance: statement.balance, entry_ids: [...chosen] }),
    onSuccess: () => { setMessage({ ok: true, text: t('money.reconciled') }); setPicked(null); setStatement({ ...statement, balance: '' }); client.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (err) => setMessage({ ok: false, text: errorOf(err, t('admin.actions.error')) }),
  })
  if (!data) return <p className="text-slate-500">{t('common.loading')}</p>
  return (
    <div className="space-y-3">
      <p className="max-w-3xl text-sm text-slate-600">{t('money.reconcileHelp')}</p>
      {data.last && <p className="text-sm text-slate-500">{t('money.lastStatement', { date: data.last.statement_date })}: <Money value={data.last.statement_balance} currency={c} /></p>}
      {message && <p role={message.ok ? 'status' : 'alert'} className={`rounded p-3 text-sm ${message.ok ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'}`}>{message.text}</p>}
      {entries.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('money.allMatched')}</p> : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="w-full text-start text-sm">
            <thead className="bg-slate-50"><tr><th className="p-2"><span className="sr-only">{t('money.onStatement')}</span></th>{['date', 'what', 'amount'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`money.cols.${k}`)}</th>)}</tr></thead>
            <tbody>
              {entries.map((e) => (
                <tr key={e.id} className="border-t">
                  <td className="p-2"><input type="checkbox" aria-label={t('money.onStatementFor', { date: e.date, amount: e.amount })} checked={chosen.has(e.id)} onChange={() => toggle(e.id)} disabled={!canManage} /></td>
                  <td className="p-2" dir="ltr">{e.date}</td>
                  <td className="p-2">{kindLabel(t, e)}{e.description && <span className="text-slate-500"> · <bdi>{e.description}</bdi></span>}</td>
                  <td className={`p-2 ${Number(e.amount) < 0 ? 'text-red-700' : 'text-green-700'}`}><Money value={e.amount} currency={c} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {canManage && (
        <div className="grid gap-3 rounded-lg border bg-white p-4 sm:grid-cols-4">
          <label className="text-sm">{t('money.statementDate')}<DateInput className="mt-1 block w-full rounded border p-2" value={statement.date} onChange={(e) => setStatement({ ...statement, date: e.target.value })} /></label>
          <label className="text-sm">{t('money.statementBalance')}<input type="number" step="0.01" dir="ltr" className="mt-1 block w-full rounded border p-2" value={statement.balance} onChange={(e) => setStatement({ ...statement, balance: e.target.value })} /></label>
          <div className="text-sm"><p className="text-slate-500">{t('money.ourBalance')}</p><p className="mt-2 font-bold"><Money value={cleared.toFixed(2)} currency={c} /></p></div>
          <div className="text-sm"><p className="text-slate-500">{t('money.difference')}</p><p className={`mt-2 font-bold ${difference === null ? '' : Math.abs(difference) < 0.005 ? 'text-green-700' : 'text-red-700'}`}>{difference === null ? '—' : <Money value={difference.toFixed(2)} currency={c} />}</p></div>
          <div className="sm:col-span-4">
            <button type="button" disabled={difference === null || Math.abs(difference) >= 0.005 || finish.isPending} onClick={() => finish.mutate()} className="cta bg-brand text-white disabled:opacity-50">{t('money.finish')}</button>
          </div>
        </div>
      )}
    </div>
  )
}

export default function MoneyLedgerPage({ base }) {
  const { t } = useTranslation()
  const { id } = useParams()
  const [tab, setTab] = useState('book')
  const { data } = useQuery({ queryKey: ['finance', 'ledger', id, { from: '', to: '' }], queryFn: async () => (await api.get(`/finance/money-accounts/${id}/ledger`)).data.data })
  const list = useQuery({ queryKey: ['finance', 'money-accounts'], queryFn: async () => (await api.get('/finance/money-accounts')).data })
  const account = data?.account
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{account?.name ?? t('money.book')}</h1>
        <Link to={`${base}/finance/money`} className="rounded border px-4 py-1.5 text-brand">{t('money.title')}</Link>
      </div>
      {account && <p className="text-lg"><span className="text-sm text-slate-500">{t('money.balance')}: </span><b><Money value={account.balance} currency={data.currency} /></b></p>}
      <div role="tablist" className="flex gap-2">
        {['book', 'reconcile'].map((k) => <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={`rounded-full border px-4 py-1.5 text-sm ${tab === k ? 'bg-brand text-white' : 'bg-white'}`}>{t(`money.tabs.${k}`)}</button>)}
      </div>
      {tab === 'book' ? <Ledger id={id} /> : <Reconcile id={id} canManage={Boolean(list.data?.can_manage)} />}
    </section>
  )
}
