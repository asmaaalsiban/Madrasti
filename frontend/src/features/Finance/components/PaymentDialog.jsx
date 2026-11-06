import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { DateInput } from '../../../components/shared/DateInput'

const METHODS = ['cash', 'bank_transfer', 'card', 'cheque', 'online']
const today = () => new Date().toISOString().slice(0, 10)

// Record a payment against a fee; it is spread over the oldest installments (or the chosen one first).
export default function PaymentDialog({ fee, currency, payerDefault = '', onClose, onDone }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ amount: fee.remaining, method: 'cash', paid_on: today(), reference: '', note: '', installment_id: '', payer_name: payerDefault ?? '' })
  const [accountId, setAccountId] = useState('') // '' = the usual account for the method (cash box for cash and cheques, the bank for the rest)
  const [error, setError] = useState('')
  const accounts = useQuery({ queryKey: ['finance', 'money-accounts'], queryFn: async () => (await api.get('/finance/money-accounts')).data })
  const usable = (Array.isArray(accounts.data?.data) ? accounts.data.data : []).filter((a) => a.is_active)
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  const save = useMutation({
    mutationFn: () => api.post('/finance/payments', {
      student_fee_id: fee.id, amount: form.amount, method: form.method, paid_on: form.paid_on, reference: form.reference || null, payer_name: form.payer_name || null, note: form.note || null,
      installment_id: form.installment_id ? Number(form.installment_id) : null, money_account_id: accountId ? Number(accountId) : null,
    }).then((r) => r.data.data),
    onSuccess: (payment) => { client.invalidateQueries({ queryKey: ['finance'] }); onDone(payment) },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || t('admin.actions.error')),
  })

  return (
    <Modal open title={t('finance.recordPaymentFor', { fee: fee.description })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <p className="text-sm text-slate-600">{t('finance.owed')}: <span dir="ltr">{fee.remaining} {currency}</span></p>
        <label className="block text-sm">{t('finance.fields.amount')}<input required type="number" step="0.01" min="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.amount} onChange={set('amount')} /></label>
        <label className="block text-sm">{t('finance.fields.method')}
          <select className="mt-1 w-full rounded border p-2" value={form.method} onChange={set('method')}>{METHODS.map((m) => <option key={m} value={m}>{t(`finance.methods.${m}`)}</option>)}</select>
        </label>
        {usable.length > 1 && (
          <label className="block text-sm">{t('money.intoAccount')}
            <select className="mt-1 w-full rounded border p-2" value={accountId} onChange={(e) => setAccountId(e.target.value)}>
              <option value="">{t('money.usual')}</option>
              {usable.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </select>
          </label>
        )}
        <label className="block text-sm">{t('finance.fields.paidOn')}<DateInput required max={today()} className="mt-1 w-full rounded border p-2" value={form.paid_on} onChange={set('paid_on')} /></label>
        <label className="block text-sm">{t('finance.fields.installment')}
          <select className="mt-1 w-full rounded border p-2" value={form.installment_id} onChange={set('installment_id')}>
            <option value="">{t('finance.oldestFirst')}</option>
            {fee.installments.filter((i) => Number(i.remaining) > 0).map((i) => <option key={i.id} value={i.id}>{`#${i.number} · ${i.due_date} · ${i.remaining}`}</option>)}
          </select>
        </label>
        <label className="block text-sm">{t('finance.fields.payerName')}<input className="mt-1 w-full rounded border p-2" value={form.payer_name} onChange={set('payer_name')} /></label>
        <label className="block text-sm">{t('finance.fields.reference')}<input className="mt-1 w-full rounded border p-2" value={form.reference} onChange={set('reference')} /></label>
        <label className="block text-sm">{t('finance.fields.note')}<input className="mt-1 w-full rounded border p-2" value={form.note} onChange={set('note')} /></label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50" disabled={save.isPending}>{t('finance.save')}</button>
        </div>
      </form>
    </Modal>
  )
}
