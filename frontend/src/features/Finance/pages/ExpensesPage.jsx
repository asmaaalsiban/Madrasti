import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Plus, Tags, Users } from 'lucide-react'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import Modal from '../../../components/shared/Modal'
import { DateInput } from '../../../components/shared/DateInput'
import Money from '../components/Money'

const today = () => new Date().toISOString().slice(0, 10)
const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const STATUS = { unpaid: 'bg-slate-100 text-slate-700', partial: 'bg-amber-100 text-amber-800', paid: 'bg-green-100 text-green-800', overdue: 'bg-red-100 text-red-700' }

function Field({ label, children }) { return <label className="block text-sm">{label}{children}</label> }

function PayFields({ accounts, methods, value, onChange, t }) {
  const set = (k) => (e) => onChange({ ...value, [k]: e.target.value })
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <Field label={t('expenses.fromAccount')}>
        <select required className="mt-1 w-full rounded border p-2" value={value.money_account_id} onChange={set('money_account_id')}>
          <option value="" disabled>—</option>
          {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </select>
      </Field>
      <Field label={t('expenses.method')}>
        <select className="mt-1 w-full rounded border p-2" value={value.method} onChange={set('method')}>{methods.map((m) => <option key={m} value={m}>{t(`finance.methods.${m}`)}</option>)}</select>
      </Field>
      <Field label={t('expenses.paidOn')}><DateInput required max={today()} className="mt-1 w-full rounded border p-2" value={value.paid_on} onChange={set('paid_on')} /></Field>
      <Field label={t('expenses.payRef')}><input className="mt-1 w-full rounded border p-2" value={value.reference} onChange={set('reference')} /></Field>
    </div>
  )
}

function BillDialog({ bill, options, onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const lang = i18n.language
  const [form, setForm] = useState({ expense_date: bill?.date ?? today(), expense_category_id: String(bill?.category?.id ?? ''), supplier_id: String(bill?.supplier?.id ?? ''), description: bill?.description ?? '', reference: bill?.reference ?? '', amount: bill?.amount ?? '', due_date: bill?.due_date ?? '', procurement_request_id: bill?.procurement_request_id ? String(bill.procurement_request_id) : '' })
  const [payNow, setPayNow] = useState(false)
  const [pay, setPay] = useState({ money_account_id: String(options.accounts[0]?.id ?? ''), method: 'cash', paid_on: today(), reference: '' })
  const [error, setError] = useState('')
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const requests = useQuery({ queryKey: ['procurement', 'approved'], enabled: !bill, queryFn: async () => (await api.get('/procurement')).data.data })
  const approved = (requests.data ?? []).filter((r) => r.status === 'approved')
  const save = useMutation({
    mutationFn: () => {
      const body = { ...form, supplier_id: form.supplier_id ? Number(form.supplier_id) : null, expense_category_id: Number(form.expense_category_id), due_date: form.due_date || null, reference: form.reference || null, procurement_request_id: form.procurement_request_id ? Number(form.procurement_request_id) : null }
      if (!bill && payNow) body.pay_now = { ...pay, money_account_id: Number(pay.money_account_id), reference: pay.reference || null }
      return bill ? api.put(`/finance/expenses/${bill.id}`, body) : api.post('/finance/expenses', body)
    },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['finance'] }); client.invalidateQueries({ queryKey: ['procurement'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const pickRequest = (e) => {
    const r = approved.find((x) => String(x.id) === e.target.value)
    setForm({ ...form, procurement_request_id: e.target.value, ...(r ? { description: form.description || r.title, amount: form.amount || r.estimated_cost || '' } : {}) })
  }
  return (
    <Modal open size="lg" title={bill ? t('expenses.editBill') : t('expenses.newBill')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {!bill && approved.length > 0 && (
          <Field label={t('expenses.fromRequest')}>
            <select className="mt-1 w-full rounded border p-2" value={form.procurement_request_id} onChange={pickRequest}>
              <option value="">—</option>
              {approved.map((r) => <option key={r.id} value={r.id}>{r.title}</option>)}
            </select>
          </Field>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('expenses.date')}><DateInput required max={today()} className="mt-1 w-full rounded border p-2" value={form.expense_date} onChange={set('expense_date')} /></Field>
          <Field label={t('expenses.category')}>
            <select required className="mt-1 w-full rounded border p-2" value={form.expense_category_id} onChange={set('expense_category_id')}>
              <option value="" disabled>—</option>
              {options.categories.map((c) => <option key={c.id} value={c.id}>{c[`name_${lang}`] || c.name_ar}</option>)}
            </select>
          </Field>
          <Field label={t('expenses.supplier')}>
            <select className="mt-1 w-full rounded border p-2" value={form.supplier_id} onChange={set('supplier_id')}>
              <option value="">—</option>
              {options.suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </Field>
          <Field label={t('expenses.invoiceNo')}><input className="mt-1 w-full rounded border p-2" value={form.reference} onChange={set('reference')} /></Field>
        </div>
        <Field label={t('expenses.description')}><input required className="mt-1 w-full rounded border p-2" value={form.description} onChange={set('description')} /></Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('expenses.amount')}><input required type="number" step="0.01" min="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.amount} onChange={set('amount')} /></Field>
          <Field label={t('expenses.dueDate')}><DateInput className="mt-1 w-full rounded border p-2" value={form.due_date} onChange={set('due_date')} /></Field>
        </div>
        {!bill && (
          <div className="space-y-3 rounded border p-3">
            <label className="flex items-center gap-2 text-sm font-medium"><input type="checkbox" checked={payNow} onChange={(e) => setPayNow(e.target.checked)} />{t('expenses.payNow')}</label>
            {payNow && <PayFields accounts={options.accounts} methods={options.methods} value={pay} onChange={setPay} t={t} />}
          </div>
        )}
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

function DetailDialog({ billId, options, currency, canManage, onClose, onEdit }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const lang = i18n.language
  const list = useQuery({ queryKey: ['finance', 'expenses'], queryFn: async () => (await api.get('/finance/expenses')).data })
  const bill = list.data?.data.find((b) => b.id === billId)
  const [pay, setPay] = useState({ amount: '', money_account_id: String(options.accounts[0]?.id ?? ''), method: 'cash', paid_on: today(), reference: '' })
  const [error, setError] = useState('')
  const [voiding, setVoiding] = useState(null)
  const [deleting, setDeleting] = useState(false)
  const done = () => client.invalidateQueries({ queryKey: ['finance'] })
  const add = useMutation({
    mutationFn: () => api.post(`/finance/expenses/${billId}/payments`, { ...pay, amount: pay.amount || bill.remaining, money_account_id: Number(pay.money_account_id), reference: pay.reference || null }),
    onSuccess: () => { setError(''); setPay({ ...pay, amount: '', reference: '' }); done() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const void_ = useMutation({ mutationFn: (id) => api.delete(`/finance/expense-payments/${id}`), onSuccess: done, onError: (err) => setError(errorOf(err, t('admin.actions.error'))), onSettled: () => setVoiding(null) })
  const del = useMutation({ mutationFn: () => api.delete(`/finance/expenses/${billId}`), onSuccess: () => { done(); onClose() }, onError: (err) => setError(errorOf(err, t('admin.actions.error'))), onSettled: () => setDeleting(false) })
  if (!bill) return null
  return (
    <Modal open size="lg" title={bill.description} onClose={onClose}>
      <div className="space-y-4">
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <dl className="grid gap-2 text-sm sm:grid-cols-3">
          <div><dt className="text-slate-500">{t('expenses.category')}</dt><dd>{bill.category[`name_${lang}`] || bill.category.name_ar}</dd></div>
          <div><dt className="text-slate-500">{t('expenses.supplier')}</dt><dd>{bill.supplier?.name ?? '—'}</dd></div>
          <div><dt className="text-slate-500">{t('expenses.invoiceNo')}</dt><dd dir="ltr">{bill.reference ?? '—'}</dd></div>
          <div><dt className="text-slate-500">{t('expenses.amount')}</dt><dd className="font-bold"><Money value={bill.amount} currency={currency} /></dd></div>
          <div><dt className="text-slate-500">{t('expenses.paid')}</dt><dd><Money value={bill.paid} currency={currency} /></dd></div>
          <div><dt className="text-slate-500">{t('expenses.remaining')}</dt><dd className="font-bold"><Money value={bill.remaining} currency={currency} /></dd></div>
        </dl>
        <div>
          <h3 className="mb-1 text-sm font-bold">{t('expenses.payments')}</h3>
          {bill.payments.length === 0 ? <p className="text-sm text-slate-500">{t('expenses.noPayments')}</p> : (
            <ul className="divide-y rounded border text-sm">
              {bill.payments.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 p-2">
                  <span><span dir="ltr">{p.paid_on}</span> · <Money value={p.amount} currency={currency} /> · {p.account?.name} · {t(`finance.methods.${p.method}`)}{p.reference && <> · <bdi dir="ltr">{p.reference}</bdi></>}</span>
                  {canManage && <button type="button" className="text-red-700 underline" onClick={() => setVoiding(p)}>{t('expenses.cancelPayment')}</button>}
                </li>
              ))}
            </ul>
          )}
        </div>
        {canManage && Number(bill.remaining) > 0 && (
          <form className="space-y-3 rounded border p-3" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
            <h3 className="text-sm font-bold">{t('expenses.addPayment')}</h3>
            <Field label={t('expenses.amount')}><input type="number" step="0.01" min="0.01" max={bill.remaining} dir="ltr" placeholder={bill.remaining} className="mt-1 w-full rounded border p-2 sm:w-1/2" value={pay.amount} onChange={(e) => setPay({ ...pay, amount: e.target.value })} /></Field>
            <PayFields accounts={options.accounts} methods={options.methods} value={pay} onChange={(v) => setPay({ ...pay, ...v })} t={t} />
            <button type="submit" disabled={add.isPending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('expenses.pay')}</button>
          </form>
        )}
        <div className="flex flex-wrap justify-between gap-2">
          {canManage ? <div className="flex gap-2"><button type="button" className="rounded border px-3 py-1.5" onClick={() => onEdit(bill)}>{t('admin.actions.edit')}</button>
            {bill.payments.length === 0 && <button type="button" className="rounded border border-red-300 px-3 py-1.5 text-red-700" onClick={() => setDeleting(true)}>{t('admin.actions.delete')}</button>}</div> : <span />}
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('common.back')}</button>
        </div>
      </div>
      <ConfirmDialog open={voiding !== null} message={t('expenses.confirmCancel')} confirmLabel={t('expenses.cancelPayment')} onConfirm={() => void_.mutate(voiding.id)} onCancel={() => setVoiding(null)} />
      <ConfirmDialog open={deleting} message={t('admin.actions.confirmDelete')} onConfirm={() => del.mutate()} onCancel={() => setDeleting(false)} />
    </Modal>
  )
}

function CategoriesDialog({ canManage, onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ name_ar: '', name_en: '' })
  const [error, setError] = useState('')
  const { data } = useQuery({ queryKey: ['finance', 'expense-categories'], queryFn: async () => (await api.get('/finance/expense-categories')).data.data })
  const done = () => client.invalidateQueries({ queryKey: ['finance'] })
  const add = useMutation({ mutationFn: () => api.post('/finance/expense-categories', form), onSuccess: () => { setForm({ name_ar: '', name_en: '' }); setError(''); done() }, onError: (err) => setError(errorOf(err, t('admin.actions.error'))) })
  const toggle = useMutation({ mutationFn: (c) => api.put(`/finance/expense-categories/${c.id}`, { is_active: !c.is_active }), onSuccess: done })
  return (
    <Modal open size="lg" title={t('expenses.categories')} onClose={onClose}>
      <div className="space-y-3">
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <ul className="divide-y rounded border text-sm">
          {(data ?? []).map((c) => (
            <li key={c.id} className={`flex items-center justify-between gap-2 p-2 ${c.is_active ? '' : 'text-slate-400'}`}>
              <span>{c[`name_${i18n.language}`] || c.name_ar} <span className="text-xs text-slate-400">({c.bills})</span></span>
              {canManage && <button type="button" className="text-brand underline" onClick={() => toggle.mutate(c)}>{c.is_active ? t('expenses.switchOff') : t('expenses.switchOn')}</button>}
            </li>
          ))}
        </ul>
        {canManage && (
          <form className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
            <input required aria-label={t('expenses.nameAr')} placeholder={t('expenses.nameAr')} className="rounded border p-2" value={form.name_ar} onChange={(e) => setForm({ ...form, name_ar: e.target.value })} />
            <input required aria-label={t('expenses.nameEn')} placeholder={t('expenses.nameEn')} dir="ltr" className="rounded border p-2" value={form.name_en} onChange={(e) => setForm({ ...form, name_en: e.target.value })} />
            <button type="submit" className="rounded bg-brand px-4 py-2 text-white">{t('admin.actions.add')}</button>
          </form>
        )}
        <div className="flex justify-end">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('common.back')}</button>
        </div>
      </div>
    </Modal>
  )
}

// What the school spends: the bills, what was paid on each and from which account, with totals and a breakdown by category.
export default function ExpensesPage({ base }) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const [filters, setFilters] = useState({ from: '', to: '', category_id: '', supplier_id: '', status: '', search: '' })
  const [dialog, setDialog] = useState(null) // { type: 'bill', bill } | { type: 'detail', id } | { type: 'categories' }
  const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
  const { data } = useQuery({ queryKey: ['finance', 'expenses', params], queryFn: async () => (await api.get('/finance/expenses', { params })).data })
  const all = useQuery({ queryKey: ['finance', 'expenses'], queryFn: async () => (await api.get('/finance/expenses')).data })
  const set = (k) => (e) => setFilters({ ...filters, [k]: e.target.value })
  const c = data?.currency
  const options = { categories: all.data?.categories ?? [], suppliers: all.data?.suppliers ?? [], accounts: all.data?.accounts ?? [], methods: all.data?.methods ?? [] }
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('expenses.title')}</h1>
        <div className="flex flex-wrap gap-2">
          <Link to={`${base}/finance/suppliers`} className="inline-flex items-center gap-2 rounded border px-4 py-1.5 text-brand"><Users size={16} aria-hidden />{t('expenses.suppliers')}</Link>
          <button type="button" className="inline-flex items-center gap-2 rounded border px-4 py-1.5 text-brand" onClick={() => setDialog({ type: 'categories' })}><Tags size={16} aria-hidden />{t('expenses.categories')}</button>
          {data?.can_manage && <button type="button" className="inline-flex items-center gap-2 rounded bg-brand px-4 py-1.5 text-white" onClick={() => setDialog({ type: 'bill' })}><Plus size={16} aria-hidden />{t('expenses.newBill')}</button>}
        </div>
      </div>

      {data && (
        <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {['total', 'paid', 'remaining', 'overdue'].map((k) => (
            <div key={k} className={`rounded-lg border bg-white p-4 ${k === 'overdue' && Number(data.summary.overdue) > 0 ? 'border-red-300' : ''}`}><dt className="text-sm text-slate-500">{t(`expenses.sum.${k}`)}</dt><dd className="text-lg font-bold"><Money value={data.summary[k]} currency={c} /></dd></div>
          ))}
        </dl>
      )}

      <div className="grid gap-2 rounded-lg border bg-white p-3 sm:grid-cols-3 lg:grid-cols-6">
        <label className="text-sm">{t('money.fromDate')}<DateInput className="mt-1 w-full rounded border p-1.5" value={filters.from} onChange={set('from')} /></label>
        <label className="text-sm">{t('money.toDate')}<DateInput className="mt-1 w-full rounded border p-1.5" value={filters.to} onChange={set('to')} /></label>
        <label className="text-sm">{t('expenses.category')}
          <select className="mt-1 w-full rounded border p-1.5" value={filters.category_id} onChange={set('category_id')}><option value="">{t('finance.all')}</option>{options.categories.map((x) => <option key={x.id} value={x.id}>{x[`name_${lang}`] || x.name_ar}</option>)}</select>
        </label>
        <label className="text-sm">{t('expenses.supplier')}
          <select className="mt-1 w-full rounded border p-1.5" value={filters.supplier_id} onChange={set('supplier_id')}><option value="">{t('finance.all')}</option>{options.suppliers.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select>
        </label>
        <label className="text-sm">{t('expenses.status')}
          <select className="mt-1 w-full rounded border p-1.5" value={filters.status} onChange={set('status')}><option value="">{t('finance.all')}</option>{['open', 'unpaid', 'partial', 'paid', 'overdue'].map((x) => <option key={x} value={x}>{t(`expenses.statuses.${x}`)}</option>)}</select>
        </label>
        <label className="text-sm">{t('admin.actions.search')}<input type="search" className="mt-1 w-full rounded border p-1.5" value={filters.search} onChange={set('search')} /></label>
      </div>

      {data?.summary.by_category.length > 0 && (
        <ul className="flex flex-wrap gap-2 text-sm" aria-label={t('expenses.byCategory')}>
          {data.summary.by_category.map((r) => <li key={r.category.id} className="rounded-full bg-sky-tint px-3 py-1 text-deep-harbor">{r.category[`name_${lang}`] || r.category.name_ar}: <b><Money value={r.total} currency={c} /></b></li>)}
        </ul>
      )}

      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : data.data.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('expenses.none')}</p> : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="w-full text-start text-sm">
            <thead className="bg-slate-50"><tr>{['date', 'description', 'category', 'amount', 'paid', 'status'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`expenses.cols.${k}`)}</th>)}<th><span className="sr-only">{t('a11y.actions')}</span></th></tr></thead>
            <tbody>
              {data.data.map((b) => (
                <tr key={b.id} className="border-t">
                  <td className="p-2" dir="ltr">{b.date}</td>
                  <td className="p-2 font-medium"><bdi>{b.description}</bdi>{b.supplier && <span className="block text-xs font-normal text-slate-500">{b.supplier.name}</span>}</td>
                  <td className="p-2">{b.category[`name_${lang}`] || b.category.name_ar}</td>
                  <td className="p-2"><Money value={b.amount} currency={c} /></td>
                  <td className="p-2"><Money value={b.paid} currency={c} /></td>
                  <td className="p-2"><span className={`rounded-full px-2 py-0.5 text-xs ${STATUS[b.status]}`}>{t(`expenses.statuses.${b.status}`)}</span></td>
                  <td className="p-2 text-end"><button type="button" className="rounded border px-3 py-1 text-brand" onClick={() => setDialog({ type: 'detail', id: b.id })}>{t('expenses.open')}</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {dialog?.type === 'bill' && data && <BillDialog bill={dialog.bill} options={options} onClose={() => setDialog(null)} />}
      {dialog?.type === 'detail' && data && <DetailDialog billId={dialog.id} options={options} currency={c} canManage={data.can_manage} onClose={() => setDialog(null)} onEdit={(bill) => setDialog({ type: 'bill', bill })} />}
      {dialog?.type === 'categories' && <CategoriesDialog canManage={Boolean(data?.can_manage)} onClose={() => setDialog(null)} />}
    </section>
  )
}
