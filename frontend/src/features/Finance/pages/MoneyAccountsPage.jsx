import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeftRight, Banknote, Landmark, Pencil, Plus, SlidersHorizontal } from 'lucide-react'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { DateInput } from '../../../components/shared/DateInput'
import Money from '../components/Money'

const today = () => new Date().toISOString().slice(0, 10)
const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

function Form({ title, onClose, onSubmit, pending, error, children, submitLabel }) {
  const { t } = useTranslation()
  return (
    <Modal open title={title} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); onSubmit() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {children}
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={pending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{submitLabel ?? t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

function AccountDialog({ account, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ name: account?.name ?? '', type: account?.type ?? 'cash', opening_balance: account?.opening_balance ?? '0', bank_name: account?.bank_name ?? '', iban: account?.iban ?? '', is_active: account?.is_active ?? true })
  const [error, setError] = useState('')
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () => { const body = { ...form, bank_name: form.bank_name || null, iban: form.iban || null, ...(account ? { type: undefined } : {}) }; return account ? api.put(`/finance/money-accounts/${account.id}`, body) : api.post('/finance/money-accounts', body) },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  return (
    <Form title={account ? t('money.editAccount') : t('money.newAccount')} onClose={onClose} onSubmit={() => save.mutate()} pending={save.isPending} error={error}>
      <label className="block text-sm">{t('money.name')}<input required className="mt-1 w-full rounded border p-2" value={form.name} onChange={set('name')} /></label>
      {!account && (
        <label className="block text-sm">{t('money.type')}
          <select className="mt-1 w-full rounded border p-2" value={form.type} onChange={set('type')}>{['cash', 'bank'].map((k) => <option key={k} value={k}>{t(`money.types.${k}`)}</option>)}</select>
        </label>
      )}
      <label className="block text-sm">{t('money.opening')}<input type="number" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.opening_balance} onChange={set('opening_balance')} /></label>
      {(account?.type ?? form.type) === 'bank' && (
        <>
          <label className="block text-sm">{t('money.bankName')}<input className="mt-1 w-full rounded border p-2" value={form.bank_name} onChange={set('bank_name')} /></label>
          <label className="block text-sm">{t('money.iban')}<input dir="ltr" className="mt-1 w-full rounded border p-2" value={form.iban} onChange={set('iban')} /></label>
        </>
      )}
      {account && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />{t('money.active')}</label>}
    </Form>
  )
}

function TransferDialog({ accounts, from, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const active = accounts.filter((a) => a.is_active)
  const [form, setForm] = useState({ from_id: String(from?.id ?? active[0]?.id ?? ''), to_id: String(active.find((a) => a.id !== (from?.id ?? active[0]?.id))?.id ?? ''), amount: '', date: today(), note: '' })
  const [error, setError] = useState('')
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () => api.post('/finance/money-transfers', { from_id: Number(form.from_id), to_id: Number(form.to_id), amount: form.amount, date: form.date, note: form.note || null }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const select = (k) => (
    <select required className="mt-1 w-full rounded border p-2" value={form[k]} onChange={set(k)}>
      <option value="" disabled>—</option>
      {active.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
    </select>
  )
  return (
    <Form title={t('money.transfer')} onClose={onClose} onSubmit={() => save.mutate()} pending={save.isPending} error={error} submitLabel={t('money.doTransfer')}>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm">{t('money.from')}{select('from_id')}</label>
        <label className="block text-sm">{t('money.to')}{select('to_id')}</label>
      </div>
      <label className="block text-sm">{t('money.amount')}<input required type="number" step="0.01" min="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.amount} onChange={set('amount')} /></label>
      <label className="block text-sm">{t('money.date')}<DateInput required max={today()} className="mt-1 w-full rounded border p-2" value={form.date} onChange={set('date')} /></label>
      <label className="block text-sm">{t('money.note')}<input className="mt-1 w-full rounded border p-2" value={form.note} onChange={set('note')} /></label>
    </Form>
  )
}

function AdjustDialog({ account, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ direction: 'in', amount: '', date: today(), note: '' })
  const [error, setError] = useState('')
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () => api.post(`/finance/money-accounts/${account.id}/adjustments`, form),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  return (
    <Form title={t('money.adjustFor', { name: account.name })} onClose={onClose} onSubmit={() => save.mutate()} pending={save.isPending} error={error}>
      <p className="text-sm text-slate-600">{t('money.adjustHelp')}</p>
      <label className="block text-sm">{t('money.direction')}
        <select className="mt-1 w-full rounded border p-2" value={form.direction} onChange={set('direction')}>{['in', 'out'].map((k) => <option key={k} value={k}>{t(`money.directions.${k}`)}</option>)}</select>
      </label>
      <label className="block text-sm">{t('money.amount')}<input required type="number" step="0.01" min="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.amount} onChange={set('amount')} /></label>
      <label className="block text-sm">{t('money.date')}<DateInput required max={today()} className="mt-1 w-full rounded border p-2" value={form.date} onChange={set('date')} /></label>
      <label className="block text-sm">{t('money.reason')}<input required className="mt-1 w-full rounded border p-2" value={form.note} onChange={set('note')} /></label>
    </Form>
  )
}

// The school's cash boxes and bank accounts: what is in each, moving money between them, corrections, and the book of each.
export default function MoneyAccountsPage({ base }) {
  const { t } = useTranslation()
  const [dialog, setDialog] = useState(null) // { type, account }
  const { data } = useQuery({ queryKey: ['finance', 'money-accounts'], queryFn: async () => (await api.get('/finance/money-accounts')).data })
  const accounts = data?.data ?? []
  const c = data?.currency
  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('money.title')}</h1>
        {data?.can_manage && (
          <div className="flex gap-2">
            <button type="button" className="inline-flex items-center gap-2 rounded border px-4 py-1.5 text-brand" onClick={() => setDialog({ type: 'transfer' })}><ArrowLeftRight size={16} aria-hidden />{t('money.transfer')}</button>
            <button type="button" className="inline-flex items-center gap-2 rounded bg-brand px-4 py-1.5 text-white" onClick={() => setDialog({ type: 'account' })}><Plus size={16} aria-hidden />{t('money.newAccount')}</button>
          </div>
        )}
      </div>
      <p className="max-w-3xl text-sm text-slate-600">{t('money.help')}</p>
      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : (
        <>
          <div className="rounded-lg border bg-white p-4"><p className="text-sm text-slate-500">{t('money.total')}</p><p className="text-2xl font-extrabold"><Money value={data.total} currency={c} /></p></div>
          <div className="grid gap-4 md:grid-cols-2">
            {accounts.map((a) => (
              <article key={a.id} className={`space-y-3 rounded-lg border bg-white p-4 ${a.is_active ? '' : 'opacity-60'}`}>
                <header className="flex items-start justify-between gap-2">
                  <div>
                    <h2 className="flex items-center gap-2 text-lg font-extrabold">{a.type === 'bank' ? <Landmark aria-hidden size={20} /> : <Banknote aria-hidden size={20} />}{a.name}
                      {!a.is_active && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal">{t('money.off')}</span>}</h2>
                    <p className="text-sm text-slate-500">{t(`money.types.${a.type}`)}{a.bank_name ? ` · ${a.bank_name}` : ''}{a.iban && <> · <bdi dir="ltr">{a.iban}</bdi></>}</p>
                  </div>
                  {data.can_manage && <button type="button" aria-label={t('admin.actions.edit')} className="rounded border p-1.5" onClick={() => setDialog({ type: 'account', account: a })}><Pencil size={16} aria-hidden /></button>}
                </header>
                <p className="text-3xl font-extrabold"><Money value={a.balance} currency={c} /></p>
                {a.unmatched > 0 && a.type === 'bank' && <p className="text-xs text-amber-700">{t('money.unmatched', { count: a.unmatched })}</p>}
                <div className="flex flex-wrap gap-2 text-sm">
                  <Link to={`${base}/finance/money/${a.id}`} className="rounded border px-3 py-1 text-brand">{t('money.book')}</Link>
                  {data.can_manage && a.is_active && <button type="button" className="inline-flex items-center gap-1 rounded border px-3 py-1 text-brand" onClick={() => setDialog({ type: 'transfer', account: a })}><ArrowLeftRight size={14} aria-hidden />{t('money.transfer')}</button>}
                  {data.can_manage && a.is_active && <button type="button" className="inline-flex items-center gap-1 rounded border px-3 py-1 text-brand" onClick={() => setDialog({ type: 'adjust', account: a })}><SlidersHorizontal size={14} aria-hidden />{t('money.adjust')}</button>}
                </div>
              </article>
            ))}
          </div>
        </>
      )}
      {dialog?.type === 'account' && <AccountDialog account={dialog.account} onClose={() => setDialog(null)} />}
      {dialog?.type === 'transfer' && <TransferDialog accounts={accounts} from={dialog.account} onClose={() => setDialog(null)} />}
      {dialog?.type === 'adjust' && <AdjustDialog account={dialog.account} onClose={() => setDialog(null)} />}
    </section>
  )
}
