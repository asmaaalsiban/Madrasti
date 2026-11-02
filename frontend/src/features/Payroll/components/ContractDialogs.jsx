import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import Money from '../../Finance/components/Money'
import { DateInput } from '../../../components/shared/DateInput'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const cents = (v) => Math.round(Number(v || 0) * 100)
const fmt = (c) => (c / 100).toFixed(2)

// The monthly allowances and deductions written on a contract.
function ItemsEditor({ items, onChange }) {
  const { t } = useTranslation()
  const set = (i, patch) => onChange(items.map((x, j) => (j === i ? { ...x, ...patch } : x)))
  return (
    <div className="space-y-2">
      <p className="text-sm font-medium">{t('payroll.monthlyItems')}</p>
      {items.map((it, i) => (
        <div key={i} className="flex flex-wrap items-center gap-2">
          <select aria-label={t('payroll.kind')} className="rounded border p-2" value={it.kind} onChange={(e) => set(i, { kind: e.target.value })}>
            <option value="allowance">{t('payroll.kinds.allowance')}</option>
            <option value="deduction">{t('payroll.kinds.deduction')}</option>
          </select>
          <input aria-label={t('payroll.label')} required className="min-w-0 flex-1 rounded border p-2" placeholder={t('payroll.labelHint')} value={it.label} onChange={(e) => set(i, { label: e.target.value })} />
          <input aria-label={t('payroll.amount')} required type="number" step="0.01" min="0.01" dir="ltr" className="w-28 rounded border p-2" value={it.amount} onChange={(e) => set(i, { amount: e.target.value })} />
          <button type="button" className="rounded border border-red-300 px-2 py-1 text-sm text-red-700" onClick={() => onChange(items.filter((_, j) => j !== i))}>✕</button>
        </div>
      ))}
      <button type="button" className="text-sm text-brand underline" onClick={() => onChange([...items, { kind: 'allowance', label: '', amount: '' }])}>{t('payroll.addItem')}</button>
    </div>
  )
}


// How the person is paid: bank transfer, cheque or cash, with the details (IBAN, payee...).
export function PaymentFields({ method, details, onChange }) {
  const { t } = useTranslation()
  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <label className="block text-sm">{t('payroll.payMethod')}
        <select className="mt-1 w-full rounded border p-2" value={method} onChange={(e) => onChange({ payment_method: e.target.value, payment_details: details })}>
          <option value="">—</option>
          {['bank_transfer', 'cheque', 'cash'].map((m) => <option key={m} value={m}>{t(`finance.methods.${m}`)}</option>)}
        </select>
      </label>
      <label className="block text-sm">{t('payroll.payDetails')}<input className="mt-1 w-full rounded border p-2" dir="ltr" placeholder={t('payroll.payDetailsHint')} value={details} onChange={(e) => onChange({ payment_method: method, payment_details: e.target.value })} /></label>
    </div>
  )
}

// A new contract for a member of staff.
export function NewContractDialog({ staff, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ starts_on: '', ends_on: '', base_salary: '', notes: '', payment_method: '', payment_details: '' })
  const [items, setItems] = useState([])
  const [error, setError] = useState('')
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () => api.post('/payroll/contracts', { user_id: staff.id, ...form, ends_on: form.ends_on || null, notes: form.notes || null, payment_method: form.payment_method || null, payment_details: form.payment_details || null, items }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['payroll'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  return (
    <Modal open size="lg" title={t('payroll.newContractFor', { name: staff.name })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{t('payroll.startsOn')}<DateInput required className="mt-1 w-full rounded border p-2" value={form.starts_on} onChange={set('starts_on')} /></label>
          <label className="block text-sm">{t('payroll.endsOn')}<DateInput className="mt-1 w-full rounded border p-2" value={form.ends_on} onChange={set('ends_on')} /></label>
        </div>
        <label className="block text-sm">{t('payroll.baseSalary')}<input required type="number" step="0.01" min="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.base_salary} onChange={set('base_salary')} /></label>
        <PaymentFields method={form.payment_method} details={form.payment_details} onChange={(p) => setForm({ ...form, ...p })} />
        <ItemsEditor items={items} onChange={setItems} />
        <label className="block text-sm">{t('payroll.notes')}<textarea rows={2} className="mt-1 w-full rounded border p-2" value={form.notes} onChange={set('notes')} /></label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

// Renewal with the annual raise: pick percent, amount or a new salary and see the new salary before saving.
export function RenewDialog({ staff, currency, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const c = staff.contract
  const [form, setForm] = useState({ ends_on: '', raise_type: 'percent', raise_value: '', carry_items: true, notes: '', payment_method: c.payment_method ?? '', payment_details: c.payment_details ?? '' })
  const [error, setError] = useState('')
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })
  const old = cents(c.base_salary)
  const preview = form.raise_type === 'none' ? old : form.raise_type === 'percent' ? Math.round(old * (1 + Number(form.raise_value || 0) / 100)) : form.raise_type === 'amount' ? old + cents(form.raise_value) : cents(form.raise_value)
  const save = useMutation({
    mutationFn: () => api.post(`/payroll/contracts/${c.id}/renew`, { raise_type: form.raise_type, ...(form.raise_type === 'none' ? {} : { raise_value: form.raise_value }), ends_on: form.ends_on || null, carry_items: form.carry_items, notes: form.notes || null, payment_method: form.payment_method || null, payment_details: form.payment_details || null }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['payroll'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  return (
    <Modal open title={t('payroll.renewFor', { name: staff.name })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <p className="text-sm text-slate-600">{t('payroll.currentContract', { from: c.starts_on, to: c.ends_on ?? '—' })} · <Money value={c.base_salary} currency={currency} /></p>
        <label className="block text-sm">{t('payroll.newEndsOn')}<DateInput required className="mt-1 w-full rounded border p-2" value={form.ends_on} onChange={set('ends_on')} /></label>
        <fieldset className="space-y-2 rounded border p-3">
          <legend className="px-1 text-sm font-medium">{t('payroll.annualRaise')}</legend>
          <label className="block text-sm">{t('payroll.raiseType')}
            <select className="mt-1 w-full rounded border p-2" value={form.raise_type} onChange={set('raise_type')}>
              {['percent', 'amount', 'salary', 'none'].map((x) => <option key={x} value={x}>{t(`payroll.raise.${x}`)}</option>)}
            </select>
          </label>
          {form.raise_type !== 'none' && <label className="block text-sm">{t(`payroll.raiseValue.${form.raise_type}`)}<input required type="number" step="0.01" min="0" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.raise_value} onChange={set('raise_value')} /></label>}
          <p className="rounded bg-slate-50 p-2 text-sm">{t('payroll.newSalary')}: <b><Money value={fmt(preview)} currency={currency} /></b>{preview !== old && <span className="ms-2 text-green-700" dir="ltr">{preview > old ? '+' : ''}{fmt(preview - old)}</span>}</p>
        </fieldset>
        <PaymentFields method={form.payment_method} details={form.payment_details} onChange={(p) => setForm({ ...form, ...p })} />
        {c.items.length > 0 && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.carry_items} onChange={set('carry_items')} />{t('payroll.carryItems')}</label>}
        <label className="block text-sm">{t('payroll.notes')}<input className="mt-1 w-full rounded border p-2" value={form.notes} onChange={set('notes')} /></label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending || preview <= 0} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('payroll.renew')}</button>
        </div>
      </form>
    </Modal>
  )
}

export function EndDialog({ staff, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [on, setOn] = useState(() => new Date().toISOString().slice(0, 10))
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => api.post(`/payroll/contracts/${staff.contract.id}/end`, { ends_on: on }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['payroll'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  return (
    <Modal open title={t('payroll.endFor', { name: staff.name })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('payroll.lastDay')}<DateInput required className="mt-1 w-full rounded border p-2" value={on} onChange={(e) => setOn(e.target.value)} /></label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-red-700 px-4 py-1.5 text-white disabled:opacity-50">{t('payroll.endContract')}</button>
        </div>
      </form>
    </Modal>
  )
}

// Every contract of a person, with the raise each renewal gave.
export function HistoryDialog({ staff, currency, onClose }) {
  const { t } = useTranslation()
  const { data } = useQuery({ queryKey: ['payroll', 'history', staff.id], queryFn: async () => (await api.get(`/payroll/staff/${staff.id}/contracts`)).data.data })
  return (
    <Modal open title={t('payroll.historyOf', { name: staff.name })} onClose={onClose}>
      <ul className="space-y-2">
        {(data ?? []).map((c) => (
          <li key={c.id} className="rounded border p-2 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2"><span dir="ltr">{c.starts_on} → {c.ends_on ?? '—'}</span><span className="rounded-full bg-slate-100 px-2 text-xs">{t(`payroll.status.${c.status}`)}</span></div>
            <p><Money value={c.base_salary} currency={currency} />{Number(c.raise_amount) > 0 && <span className="ms-2 text-green-700" dir="ltr">+{c.raise_amount} ({c.raise_percent}%)</span>}</p>
            {c.items.length > 0 && <p className="text-xs text-slate-500">{c.items.map((i) => `${i.label} ${i.kind === 'deduction' ? '−' : '+'}${i.amount}`).join(' · ')}</p>}
          </li>
        ))}
      </ul>
      <div className="mt-3 flex justify-end"><button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('payroll.close')}</button></div>
    </Modal>
  )
}
