import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CalendarClock, Check, PackageCheck, Pencil, Plus, ShoppingCart, Trash2, X } from 'lucide-react'
import { api } from '../../api/client'
import { DateInput } from '../../components/shared/DateInput'
import ConfirmDialog from '../../components/shared/ConfirmDialog'
import Modal from '../../components/shared/Modal'
import Money from '../Finance/components/Money'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const COLUMNS = ['pending', 'approved', 'purchased', 'received']
const CATEGORIES = ['stationery', 'teaching_aids', 'equipment', 'maintenance', 'cleaning', 'it', 'other']
const PRIORITIES = ['low', 'normal', 'high', 'urgent']
const PRIORITY_STYLE = { low: 'bg-slate-100 text-slate-600', normal: 'bg-sky-tint text-deep-harbor', high: 'bg-amber-100 text-amber-800', urgent: 'bg-red-100 text-red-700' }

function RequestDialog({ request, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ title: request?.title ?? '', description: request?.description ?? '', quantity: request?.quantity ?? 1, category: request?.category ?? 'other', priority: request?.priority ?? 'normal', estimated_cost: request?.estimated_cost ?? '', needed_by: request?.needed_by ?? '' })
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => { const body = { ...form, estimated_cost: form.estimated_cost === '' ? null : form.estimated_cost, needed_by: form.needed_by || null, description: form.description || null }; return request ? api.put(`/procurement/${request.id}`, body) : api.post('/procurement', body) },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['procurement'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  return (
    <Modal open size="lg" title={request ? t('procurement.editRequest') : t('procurement.newRequest')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('procurement.what')}<input required className="mt-1 w-full rounded border p-2" placeholder={t('procurement.whatHint')} value={form.title} onChange={set('title')} /></label>
        <label className="block text-sm">{t('procurement.details')}<textarea rows={2} className="mt-1 w-full rounded border p-2" value={form.description} onChange={set('description')} /></label>
        <div className="grid gap-3 sm:grid-cols-4">
          <label className="block text-sm">{t('procurement.quantity')}<input required type="number" min="1" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.quantity} onChange={set('quantity')} /></label>
          <label className="block text-sm">{t('procurement.category')}<select className="mt-1 w-full rounded border p-2" value={form.category} onChange={set('category')}>{CATEGORIES.map((c) => <option key={c} value={c}>{t(`procurement.categories.${c}`)}</option>)}</select></label>
          <label className="block text-sm">{t('procurement.priority')}<select className="mt-1 w-full rounded border p-2" value={form.priority} onChange={set('priority')}>{PRIORITIES.map((p) => <option key={p} value={p}>{t(`procurement.priorities.${p}`)}</option>)}</select></label>
          <label className="block text-sm">{t('procurement.estimated')}<input type="number" min="0" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.estimated_cost} onChange={set('estimated_cost')} /></label>
        </div>
        <label className="block text-sm sm:max-w-xs">{t('procurement.neededBy')}<DateInput className="mt-1 w-full rounded border p-2" value={form.needed_by} onChange={set('needed_by')} /></label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-brand px-5 py-1.5 text-white disabled:opacity-50">{t('procurement.send')}</button>
        </div>
      </form>
    </Modal>
  )
}

function RejectDialog({ request, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const save = useMutation({ mutationFn: () => api.post(`/procurement/${request.id}/reject`, { note }), onSuccess: () => { client.invalidateQueries({ queryKey: ['procurement'] }); onClose() }, onError: (err) => setError(errorOf(err, t('admin.actions.error'))) })
  return (
    <Modal open title={t('procurement.rejectTitle', { title: request.title })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('procurement.reason')}<textarea required rows={2} className="mt-1 w-full rounded border p-2" value={note} onChange={(e) => setNote(e.target.value)} /></label>
        <div className="flex justify-end gap-2"><button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button><button type="submit" disabled={save.isPending} className="rounded bg-red-600 px-5 py-1.5 text-white disabled:opacity-50">{t('procurement.reject')}</button></div>
      </form>
    </Modal>
  )
}

function PurchaseDialog({ request, currency, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const { i18n } = useTranslation()
  const [form, setForm] = useState({ supplier: '', actual_cost: request.estimated_cost ?? '', purchase_ref: '', purchased_on: '', expense_category_id: '', due_date: '' })
  const [payNow, setPayNow] = useState(false)
  const [pay, setPay] = useState({ money_account_id: '', method: 'cash', reference: '' })
  const [error, setError] = useState('')
  const { data: fin } = useQuery({ queryKey: ['finance', 'expenses', 'options'], queryFn: async () => (await api.get('/finance/expenses')).data })
  const categories = Array.isArray(fin?.categories) ? fin.categories.filter((c) => c.is_active !== false) : []
  const accounts = Array.isArray(fin?.accounts) ? fin.accounts : []
  const methods = Array.isArray(fin?.methods) ? fin.methods : ['cash']
  const save = useMutation({
    mutationFn: () => api.post(`/procurement/${request.id}/purchase`, {
      ...form, purchase_ref: form.purchase_ref || null, purchased_on: form.purchased_on || null,
      expense_category_id: form.expense_category_id || null, due_date: form.due_date || null,
      ...(payNow ? { pay_now: { ...pay, money_account_id: Number(pay.money_account_id), reference: pay.reference || null } } : {}),
    }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['procurement'] }); client.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  return (
    <Modal open size="lg" title={t('procurement.purchaseTitle', { title: request.title })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {request.estimated_cost && <p className="text-sm text-slate-600">{t('procurement.estimated')}: <Money value={request.estimated_cost} currency={currency} /></p>}
        <label className="block text-sm">{t('procurement.supplier')}<input required className="mt-1 w-full rounded border p-2" value={form.supplier} onChange={set('supplier')} /></label>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{t('procurement.actual')} ({currency})<input required type="number" min="0.01" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.actual_cost} onChange={set('actual_cost')} /></label>
          <label className="block text-sm">{t('procurement.invoice')}<input className="mt-1 w-full rounded border p-2" dir="ltr" value={form.purchase_ref} onChange={set('purchase_ref')} /></label>
        </div>
        <label className="block text-sm sm:max-w-xs">{t('procurement.boughtOn')}<DateInput className="mt-1 w-full rounded border p-2" value={form.purchased_on} onChange={set('purchased_on')} /></label>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{t('procurement.billCategory')}
            <select className="mt-1 w-full rounded border p-2" value={form.expense_category_id} onChange={set('expense_category_id')}>
              <option value="">{t('procurement.defaultCategory')}</option>
              {categories.map((c) => <option key={c.id} value={c.id}>{c[`name_${i18n.language}`] || c.name_ar}</option>)}
            </select>
          </label>
          <label className="block text-sm">{t('procurement.dueDate')}<DateInput className="mt-1 w-full rounded border p-2" value={form.due_date} onChange={set('due_date')} /></label>
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={payNow} onChange={(e) => setPayNow(e.target.checked)} />{t('procurement.paidNow')}</label>
        {payNow && (
          <div className="grid gap-3 rounded border p-3 sm:grid-cols-3">
            <label className="block text-sm">{t('procurement.payAccount')}
              <select required className="mt-1 w-full rounded border p-2" value={pay.money_account_id} onChange={(e) => setPay({ ...pay, money_account_id: e.target.value })}>
                <option value="" />
                {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
            </label>
            <label className="block text-sm">{t('procurement.payMethod')}
              <select className="mt-1 w-full rounded border p-2" value={pay.method} onChange={(e) => setPay({ ...pay, method: e.target.value })}>
                {methods.map((m) => <option key={m} value={m}>{t(`expenses.methods.${m}`, { defaultValue: m })}</option>)}
              </select>
            </label>
            <label className="block text-sm">{t('procurement.payRef')}<input dir="ltr" className="mt-1 w-full rounded border p-2" value={pay.reference} onChange={(e) => setPay({ ...pay, reference: e.target.value })} /></label>
          </div>
        )}
        <div className="flex justify-end gap-2"><button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button><button type="submit" disabled={save.isPending} className="rounded bg-brand px-5 py-1.5 text-white disabled:opacity-50">{t('procurement.recordPurchase')}</button></div>
      </form>
    </Modal>
  )
}

function Card({ r, data, onDialog }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const base = useLocation().pathname.startsWith('/admin') ? '/admin' : '/teacher'
  const act = useMutation({ mutationFn: (path) => api.post(`/procurement/${r.id}/${path}`), onSuccess: () => client.invalidateQueries({ queryKey: ['procurement'] }) })
  const date = (d) => new Date(`${d}T12:00:00`).toLocaleDateString(i18n.language === 'ar' ? 'ar-JO-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'short' })
  const btn = 'flex items-center gap-1.5 rounded px-3 py-1 text-sm'
  return (
    <article className="space-y-2 rounded-2xl border bg-snow p-3">
      <div className="flex items-start justify-between gap-2">
        <h3 className="text-base text-deep-harbor">{r.title}</h3>
        <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold ${PRIORITY_STYLE[r.priority]}`}>{t(`procurement.priorities.${r.priority}`)}</span>
      </div>
      <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600">
        <span>× <b dir="ltr">{r.quantity}</b></span>
        <span className="rounded-full bg-slate-100 px-2 py-0.5">{t(`procurement.categories.${r.category}`)}</span>
        {r.estimated_cost && <span><Money value={r.estimated_cost} currency={data.currency} /></span>}
        {r.needed_by && <span className="flex items-center gap-1"><CalendarClock size={13} aria-hidden />{date(r.needed_by)}</span>}
      </p>
      {r.description && <p className="text-sm text-slate-600">{r.description}</p>}
      <p className="text-xs text-slate-500">{r.requester.name}{r.reviewed_by && ` · ${r.status === 'rejected' ? t('procurement.declinedBy') : t('procurement.approvedBy')} ${r.reviewed_by}`}</p>
      {r.review_note && <p className="rounded-lg bg-slate-50 p-2 text-xs text-slate-600">“{r.review_note}”</p>}
      {r.supplier && <p className="rounded-lg bg-green-50 p-2 text-xs text-green-900">{t('procurement.boughtFrom', { supplier: r.supplier })} · <Money value={r.actual_cost} currency={data.currency} />{r.purchase_ref && <> · <bdi dir="ltr">{r.purchase_ref}</bdi></>}{r.expense_id && <> · <Link to={`${base}/finance/expenses`} className="underline">{t('procurement.billRecorded')}</Link></>}</p>}
      <div className="flex flex-wrap gap-2 pt-1">
        {data.can_review && r.status === 'pending' && <button type="button" disabled={act.isPending} className={`${btn} bg-green-700 text-white`} onClick={() => act.mutate('approve')}><Check size={15} aria-hidden />{t('procurement.approve')}</button>}
        {data.can_review && r.status === 'pending' && <button type="button" className={`${btn} border border-red-300 text-red-700`} onClick={() => onDialog({ type: 'reject', request: r })}><X size={15} aria-hidden />{t('procurement.reject')}</button>}
        {data.can_buy && r.status === 'approved' && <button type="button" className={`${btn} bg-brand text-white`} onClick={() => onDialog({ type: 'purchase', request: r })}><ShoppingCart size={15} aria-hidden />{t('procurement.recordPurchase')}</button>}
        {r.status === 'purchased' && (r.mine || data.can_review || data.can_buy) && <button type="button" disabled={act.isPending} className={`${btn} bg-brand text-white`} onClick={() => act.mutate('receive')}><PackageCheck size={15} aria-hidden />{t('procurement.markReceived')}</button>}
        {((r.mine && r.status === 'pending') || data.can_review) && ['pending', 'approved'].includes(r.status) && <button type="button" className={`${btn} border`} onClick={() => onDialog({ type: 'edit', request: r })}><Pencil size={15} aria-hidden />{t('admin.actions.edit')}</button>}
        {(r.mine || data.can_review) && ['pending', 'rejected'].includes(r.status) && <button type="button" aria-label={t('admin.actions.delete')} className={`${btn} border border-red-200 text-red-600`} onClick={() => onDialog({ type: 'delete', request: r })}><Trash2 size={15} aria-hidden /></button>}
      </div>
    </article>
  )
}

// The purchase board: columns for the steps (waiting, approved, bought, received) and what each person can do with a card.
export default function ProcurementPage() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [dialog, setDialog] = useState(null)
  const [tab, setTab] = useState('pending')
  const { data, isLoading } = useQuery({ queryKey: ['procurement'], queryFn: async () => (await api.get('/procurement')).data })
  const drop = useMutation({ mutationFn: (id) => api.delete(`/procurement/${id}`), onSuccess: () => { setDialog(null); client.invalidateQueries({ queryKey: ['procurement'] }) } })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  const columns = data.summary.rejected > 0 ? [...COLUMNS, 'rejected'] : COLUMNS
  const rows = (s) => data.data.filter((r) => r.status === s)
  return (
    <section className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1>{t('procurement.title')}</h1>
        <button type="button" className="flex items-center gap-2 rounded bg-brand px-5 py-2 text-white" onClick={() => setDialog({ type: 'edit' })}><Plus size={18} aria-hidden />{t('procurement.newRequest')}</button>
      </div>
      {(data.can_review || data.can_buy) && (
        <div className="grid gap-3 sm:grid-cols-3">
          <div className="rounded-lg bg-deep-harbor p-4 text-white"><p className="text-sm text-white/75">{t('procurement.waitingDecision')}</p><p className="text-3xl font-extrabold">{data.summary.pending}</p></div>
          <div className="rounded-lg border bg-snow p-4"><p className="text-sm text-slate-600">{t('procurement.toSpend')}</p><p className="text-2xl font-extrabold text-deep-harbor"><Money value={data.summary.to_spend} currency={data.currency} /></p></div>
          <div className="rounded-lg border bg-snow p-4"><p className="text-sm text-slate-600">{t('procurement.spent')}</p><p className="text-2xl font-extrabold text-deep-harbor"><Money value={data.summary.spent} currency={data.currency} /></p></div>
        </div>
      )}
      <div role="tablist" className="flex flex-wrap gap-2 lg:hidden">
        {columns.map((s) => <button key={s} type="button" role="tab" aria-selected={tab === s} className={`rounded-full border px-4 py-1.5 text-sm ${tab === s ? '!bg-brand text-white' : 'bg-snow'}`} onClick={() => setTab(s)}>{t(`procurement.status.${s}`)} ({data.summary[s]})</button>)}
      </div>
      <div className={`grid gap-4 ${columns.length > 4 ? 'lg:grid-cols-5' : 'lg:grid-cols-4'}`}>
        {columns.map((s) => (
          <div key={s} className={`space-y-3 rounded-lg bg-slate-50 p-3 ${tab === s ? '' : 'hidden'} lg:block`}>
            <h2 className="flex items-center justify-between text-sm font-bold text-deep-harbor">{t(`procurement.status.${s}`)}<span className="rounded-full bg-snow px-2 py-0.5 text-xs">{data.summary[s]}</span></h2>
            {rows(s).map((r) => <Card key={r.id} r={r} data={data} onDialog={setDialog} />)}
            {rows(s).length === 0 && <p className="py-4 text-center text-xs text-slate-400">{t('procurement.noneHere')}</p>}
          </div>
        ))}
      </div>
      {dialog?.type === 'edit' && <RequestDialog request={dialog.request} onClose={() => setDialog(null)} />}
      {dialog?.type === 'reject' && <RejectDialog request={dialog.request} onClose={() => setDialog(null)} />}
      {dialog?.type === 'purchase' && <PurchaseDialog request={dialog.request} currency={data.currency} onClose={() => setDialog(null)} />}
      <ConfirmDialog open={dialog?.type === 'delete'} message={t('procurement.deleteTitle')} description={dialog?.request?.title} busy={drop.isPending} onConfirm={() => drop.mutate(dialog.request.id)} onCancel={() => setDialog(null)} />
    </section>
  )
}
