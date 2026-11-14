import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import Modal from '../../../components/shared/Modal'
import { DateInput } from '../../../components/shared/DateInput'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const today = () => new Date().toISOString().slice(0, 10)
const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const emptyLine = () => ({ ledger_account_id: '', debit: '', credit: '' })
const sum = (lines, k) => lines.reduce((a, l) => a + Math.round(Number(l[k] || 0) * 100), 0)

function EntryDialog({ accounts, onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ entry_date: today(), memo: '' })
  const [lines, setLines] = useState([emptyLine(), emptyLine()])
  const [error, setError] = useState('')
  const debit = sum(lines, 'debit')
  const credit = sum(lines, 'credit')
  const balanced = debit > 0 && debit === credit
  const save = useMutation({
    mutationFn: () => api.post('/finance/journal', { ...form, lines: lines.map((l) => ({ ledger_account_id: Number(l.ledger_account_id), debit: l.debit || null, credit: l.credit || null })) }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['finance', 'journal'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const setLine = (i, k, v) => setLines(lines.map((l, j) => (j === i ? { ...l, [k]: v } : l)))
  return (
    <Modal open size="lg" title={t('journal.newEntry')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <div className="grid gap-3 sm:grid-cols-[12rem_1fr]">
          <label className="block text-sm">{t('journal.date')}<DateInput required className="mt-1 w-full rounded border p-2" value={form.entry_date} onChange={(e) => setForm({ ...form, entry_date: e.target.value })} /></label>
          <label className="block text-sm">{t('journal.memo')}<input required className="mt-1 w-full rounded border p-2" value={form.memo} onChange={(e) => setForm({ ...form, memo: e.target.value })} /></label>
        </div>
        <div className="space-y-2">
          {lines.map((l, i) => (
            <div key={i} className="grid gap-2 sm:grid-cols-[1fr_8rem_8rem]">
              <select required aria-label={t('journal.account')} className="rounded border p-2" value={l.ledger_account_id} onChange={(e) => setLine(i, 'ledger_account_id', e.target.value)}>
                <option value="" disabled>{t('journal.account')}</option>
                {accounts.filter((a) => a.is_active).map((a) => <option key={a.id} value={a.id}>{a.code} · {a[`name_${i18n.language}`] || a.name_ar}</option>)}
              </select>
              <input type="number" min="0" step="0.01" dir="ltr" aria-label={t('journal.debit')} placeholder={t('journal.debit')} className="rounded border p-2" value={l.debit} onChange={(e) => setLine(i, 'debit', e.target.value)} />
              <input type="number" min="0" step="0.01" dir="ltr" aria-label={t('journal.credit')} placeholder={t('journal.credit')} className="rounded border p-2" value={l.credit} onChange={(e) => setLine(i, 'credit', e.target.value)} />
            </div>
          ))}
          <button type="button" className="text-sm text-brand underline" onClick={() => setLines([...lines, emptyLine()])}>{t('journal.addLine')}</button>
        </div>
        <p className={`text-sm ${balanced ? 'text-green-700' : 'text-amber-700'}`}>{t('journal.totals', { debit: (debit / 100).toFixed(2), credit: (credit / 100).toFixed(2) })}</p>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <InteractiveHoverButton type="submit" disabled={!balanced || save.isPending} className="px-5 py-1.5 disabled:opacity-50">{t('admin.actions.save')}</InteractiveHoverButton>
        </div>
      </form>
    </Modal>
  )
}

function AccountsTab({ data, canManage }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ code: '', name_ar: '', name_en: '', type: 'expense' })
  const [error, setError] = useState('')
  const done = () => client.invalidateQueries({ queryKey: ['finance', 'ledger-accounts'] })
  const add = useMutation({ mutationFn: () => api.post('/finance/ledger-accounts', form), onSuccess: () => { setForm({ code: '', name_ar: '', name_en: '', type: 'expense' }); setError(''); done() }, onError: (err) => setError(errorOf(err, t('admin.actions.error'))) })
  const toggle = useMutation({ mutationFn: (a) => api.put(`/finance/ledger-accounts/${a.id}`, { is_active: !a.is_active }), onSuccess: done })
  return (
    <div className="space-y-3">
      <ul className="divide-y rounded-lg border bg-white text-sm">
        {data.data.map((a) => (
          <li key={a.id} className={`flex items-center justify-between gap-2 p-2 ${a.is_active ? '' : 'text-slate-400'}`}>
            <span><b dir="ltr" className="me-2">{a.code}</b>{a[`name_${i18n.language}`] || a.name_ar} <span className="text-xs text-slate-500">· {t(`journal.types.${a.type}`)}</span></span>
            {canManage && <button type="button" className="text-brand underline" onClick={() => toggle.mutate(a)}>{a.is_active ? t('expenses.switchOff') : t('expenses.switchOn')}</button>}
          </li>
        ))}
      </ul>
      {canManage && (
        <form className="grid gap-2 rounded-lg border bg-white p-3 sm:grid-cols-[6rem_1fr_1fr_9rem_auto]" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
          {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700 sm:col-span-5">{error}</p>}
          <input required dir="ltr" aria-label={t('journal.code')} placeholder={t('journal.code')} className="rounded border p-2" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          <input required aria-label={t('expenses.nameAr')} placeholder={t('expenses.nameAr')} className="rounded border p-2" value={form.name_ar} onChange={(e) => setForm({ ...form, name_ar: e.target.value })} />
          <input required dir="ltr" aria-label={t('expenses.nameEn')} placeholder={t('expenses.nameEn')} className="rounded border p-2" value={form.name_en} onChange={(e) => setForm({ ...form, name_en: e.target.value })} />
          <select aria-label={t('journal.type')} className="rounded border p-2" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>{data.types.map((x) => <option key={x} value={x}>{t(`journal.types.${x}`)}</option>)}</select>
          <InteractiveHoverButton type="submit" className="px-4 py-2">{t('admin.actions.add')}</InteractiveHoverButton>
        </form>
      )}
    </div>
  )
}

// The journal (balanced entries), the chart of accounts and the date the books are locked up to.
export default function JournalPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [tab, setTab] = useState('journal')
  const [dialog, setDialog] = useState(false)
  const [removing, setRemoving] = useState(null)
  const [lockDate, setLockDate] = useState(null)
  const accounts = useQuery({ queryKey: ['finance', 'ledger-accounts'], queryFn: async () => (await api.get('/finance/ledger-accounts')).data })
  const journal = useQuery({ queryKey: ['finance', 'journal'], queryFn: async () => (await api.get('/finance/journal')).data })
  const canManage = Boolean(journal.data?.can_manage)
  const locked = journal.data?.locked_until ?? ''
  const refresh = () => client.invalidateQueries({ queryKey: ['finance', 'journal'] })
  const lock = useMutation({ mutationFn: (d) => api.put('/finance/books-lock', { locked_until: d || null }), onSuccess: refresh })
  const del = useMutation({ mutationFn: (id) => api.delete(`/finance/journal/${id}`), onSuccess: refresh, onSettled: () => setRemoving(null) })
  const tabBtn = (k) => `rounded-full px-4 py-1.5 text-sm ${tab === k ? 'bg-brand text-white' : 'border text-brand'}`
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('journal.title')}</h1>
        {canManage && tab === 'journal' && <InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={() => setDialog(true)}><Plus size={16} aria-hidden />{t('journal.newEntry')}</InteractiveHoverButton>}
      </div>
      <div className="flex gap-2">
        <button type="button" className={tabBtn('journal')} onClick={() => setTab('journal')}>{t('journal.entries')}</button>
        <button type="button" className={tabBtn('accounts')} onClick={() => setTab('accounts')}>{t('journal.chart')}</button>
      </div>
      {tab === 'accounts' ? (accounts.data ? <AccountsTab data={accounts.data} canManage={canManage} /> : <p className="text-slate-500">{t('common.loading')}</p>) : (
        <>
          <div className="flex flex-wrap items-end gap-2 rounded-lg border bg-white p-3 text-sm">
            <label>{t('journal.lockedUntil')}<DateInput className="mt-1 block rounded border p-1.5" disabled={!canManage} value={lockDate ?? locked} onChange={(e) => setLockDate(e.target.value)} /></label>
            {canManage && <button type="button" className="rounded border px-3 py-1.5 text-brand" onClick={() => { lock.mutate(lockDate ?? locked); setLockDate(null) }}>{t('journal.saveLock')}</button>}
            <span className="text-slate-500">{locked ? t('journal.lockedNote', { date: locked }) : t('journal.open')}</span>
          </div>
          {!journal.data ? <p className="text-slate-500">{t('common.loading')}</p> : journal.data.data.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('journal.none')}</p> : (
            <div className="space-y-3">
              {journal.data.data.map((e) => (
                <article key={e.id} className="rounded-lg border bg-white p-3 text-sm">
                  <div className="flex items-start justify-between gap-2">
                    <p><b dir="ltr">{e.date}</b> · {e.memo}{!e.manual && <span className="ms-2 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{t('journal.automatic')}</span>}</p>
                    {canManage && e.manual && <button type="button" className="text-red-700 underline" onClick={() => setRemoving(e)}>{t('admin.actions.delete')}</button>}
                  </div>
                  <table className="mt-2 w-full">
                    <tbody>
                      {e.lines.map((l, i) => (
                        <tr key={i} className="border-t"><td className="py-1">{l.code} · {l[`name_${i18n.language}`] || l.name_ar}</td><td dir="ltr" className="w-24 text-end">{Number(l.debit) > 0 ? l.debit : ''}</td><td dir="ltr" className="w-24 text-end">{Number(l.credit) > 0 ? l.credit : ''}</td></tr>
                      ))}
                    </tbody>
                  </table>
                </article>
              ))}
            </div>
          )}
        </>
      )}
      {dialog && accounts.data && <EntryDialog accounts={accounts.data.data} onClose={() => setDialog(false)} />}
      <ConfirmDialog open={removing !== null} message={t('journal.confirmDelete')} confirmLabel={t('admin.actions.delete')} onConfirm={() => del.mutate(removing.id)} onCancel={() => setRemoving(null)} />
    </section>
  )
}
