import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import Modal from '../../../components/shared/Modal'
import PaymentDialog from '../components/PaymentDialog'
import ReceiptDialog from '../components/ReceiptDialog'
import Statement from '../components/Statement'

// One student's money: the statement; the accountant adds a fee, records and prints payments, cancels a wrong one.
export default function StudentAccountPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const { id } = useParams()
  const [paying, setPaying] = useState(null)
  const [receipt, setReceipt] = useState(null)
  const [voiding, setVoiding] = useState(null)
  const [deleting, setDeleting] = useState(null)
  const [adding, setAdding] = useState(null)
  const [editing, setEditing] = useState(null) // { kind: 'transport' | 'discount', fee?, value }
  const [error, setError] = useState('')

  const statement = useQuery({ queryKey: ['finance', 'student', id], queryFn: async () => (await api.get(`/finance/students/${id}`)).data.data })
  const settings = useQuery({ queryKey: ['finance', 'settings'], queryFn: async () => (await api.get('/finance/settings')).data.data })
  const canManage = settings.data?.can_manage
  const refresh = () => client.invalidateQueries({ queryKey: ['finance'] })
  const fail = (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error'))

  const voidIt = useMutation({ mutationFn: (pid) => api.delete(`/finance/payments/${pid}`), onSuccess: () => { setVoiding(null); refresh() }, onError: (e) => { setVoiding(null); fail(e) } })
  const deleteFee = useMutation({ mutationFn: (fid) => api.delete(`/finance/fees/${fid}`), onSuccess: () => { setDeleting(null); refresh() }, onError: (e) => { setDeleting(null); fail(e) } })
  const saveEdit = useMutation({
    mutationFn: () => (editing.kind === 'transport'
      ? api.put(`/finance/students/${id}/transport`, { amount: editing.value || 0 })
      : api.put(`/finance/fees/${editing.fee.id}`, { discount: editing.value || 0 })),
    onSuccess: () => { setEditing(null); setError(''); refresh() }, onError: fail,
  })
  const addFee = useMutation({
    mutationFn: () => api.post(`/finance/students/${id}/fees`, data.has_schedule
      ? { description: adding.description, total_amount: adding.total, kind: adding.kind }
      : {
        description: adding.description, total_amount: adding.total, discount: adding.discount || 0, transport_fee: adding.transport || 0, installments_count: Number(adding.count), first_due: adding.first_due, every_months: Number(adding.every),
        ...(adding.first_payment ? { first_payment: adding.first_payment, first_payment_method: adding.method, payer_name: adding.payer || null } : {}),
      }).then((r) => r.data.data),
    onSuccess: (result) => { setAdding(null); setError(''); refresh(); if (result.payments?.[0]) setReceipt({ payment: result.payments[0], fee: { description: adding.description } }) }, onError: fail,
  })

  if (statement.isLoading) return <p>{t('common.loading')}</p>
  const data = statement.data
  const mainFee = data.fees.find((f) => f.kind === 'yearly') ?? data.fees.find((f) => f.kind !== 'transport')
  const openCount = mainFee ? mainFee.installments.filter((i) => Number(i.remaining) > 0).length : 0
  const student = data.student
  const set = (key) => (e) => setAdding({ ...adding, [key]: e.target.value })

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{student[`name_${i18n.language}`] || student.name_ar} <span className="text-sm font-normal text-slate-500" dir="ltr">{student.student_number}</span></h1>
        {canManage && (
          <div className="flex flex-wrap gap-2">
            <button type="button" className="rounded border px-4 py-1.5 text-brand" onClick={() => { setError(''); setAdding({ description: '', total: '', kind: 'other', transport: '', discount: '0', count: '9', first_due: '', every: '1', first_payment: '', method: 'cash', payer: data.payer_default ?? '' }) }}>{data.has_schedule ? t('finance.addCharge') : t('finance.addFee')}</button>
          </div>
        )}
      </div>
      {error && !adding && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      <Statement
        data={data}
        onPay={canManage ? (fee) => setPaying(fee) : null} onReceipt={canManage ? (p, fee) => setReceipt({ payment: p, fee }) : null}
        onVoid={canManage ? (p) => setVoiding(p) : null} onDeleteFee={canManage ? (fee) => setDeleting(fee) : null}
        onTransport={canManage ? (fee, item) => { setError(''); setEditing({ kind: 'transport', fee, value: item.amount }) } : null}
        onDiscount={canManage ? (fee) => { setError(''); setEditing({ kind: 'discount', fee, value: fee.discount }) } : null}
      />

      {paying && <PaymentDialog fee={paying} currency={data.currency} payerDefault={data.payer_default} onClose={() => setPaying(null)} onDone={(payment) => { setPaying(null); setReceipt({ payment, fee: paying }) }} />}
      {receipt && <ReceiptDialog payment={receipt.payment} fee={receipt.fee} student={student} currency={data.currency} onClose={() => setReceipt(null)} />}
      <ConfirmDialog open={Boolean(voiding)} message={t('finance.confirmVoid', { receipt: voiding?.receipt })} confirmLabel={t('finance.voidPayment')} onConfirm={() => voidIt.mutate(voiding.id)} onCancel={() => setVoiding(null)} />
      <ConfirmDialog open={Boolean(deleting)} message={t('finance.confirmDeleteFee', { name: deleting?.description })} onConfirm={() => deleteFee.mutate(deleting.id)} onCancel={() => setDeleting(null)} />
      {editing && (
        <Modal open title={editing.kind === 'transport' ? t('finance.changeTransport') : t('finance.changeDiscountFor', { name: editing.fee.description })} onClose={() => setEditing(null)}>
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); saveEdit.mutate() }}>
            {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
            <p className="text-xs text-slate-500">{editing.kind === 'transport' ? t('finance.transportHelp') : t('finance.discountHelp')}</p>
            <label className="block text-sm">{editing.kind === 'transport' ? t('finance.fields.transportAmount') : t('finance.fields.discount')}
              <input required type="number" min="0" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={editing.value} onChange={(e) => setEditing({ ...editing, value: e.target.value })} />
            </label>
            <div className="flex justify-end gap-2">
              <button type="button" className="rounded border px-3 py-1.5" onClick={() => setEditing(null)}>{t('admin.actions.cancel')}</button>
              <button type="submit" className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50" disabled={saveEdit.isPending}>{t('admin.actions.save')}</button>
            </div>
          </form>
        </Modal>
      )}
      {adding && (
        <Modal open title={data.has_schedule ? t('finance.addCharge') : t('finance.addFee')} onClose={() => setAdding(null)}>
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); addFee.mutate() }}>
            {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
            {data.has_schedule && <p className="rounded bg-sky-50 p-2 text-sm text-sky-800">{t('finance.spreadHelp', { count: openCount })}</p>}
            {data.has_schedule && (
              <label className="block text-sm">{t('finance.fields.chargeType')}
                <select className="mt-1 w-full rounded border p-2" value={adding.kind} onChange={(e) => setAdding({ ...adding, kind: e.target.value, description: e.target.value === 'transport' ? t('finance.transport') : adding.description })}>
                  <option value="other">{t('finance.kinds.other')}</option><option value="transport">{t('finance.kinds.transport')}</option>
                </select>
              </label>
            )}
            <label className="block text-sm">{t('finance.fields.description')}<input required className="mt-1 w-full rounded border p-2" value={adding.description} onChange={set('description')} /></label>
            <label className="block text-sm">{data.has_schedule ? t('finance.fields.chargeAmount') : t('finance.fields.total')}<input required type="number" min="0.01" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={adding.total} onChange={set('total')} /></label>
            {!data.has_schedule && (
              <>
                <label className="block text-sm">{t('finance.fields.transportInPlan')}<input type="number" min="0" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={adding.transport} onChange={set('transport')} /></label>
                <label className="block text-sm">{t('finance.fields.discount')}<input type="number" min="0" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={adding.discount} onChange={set('discount')} /></label>
                <label className="block text-sm">{t('finance.fields.installments')}<input required type="number" min="1" max="24" className="mt-1 w-full rounded border p-2" value={adding.count} onChange={set('count')} /></label>
                <label className="block text-sm">{t('finance.fields.firstDue')}<input required type="date" className="mt-1 w-full rounded border p-2" value={adding.first_due} onChange={set('first_due')} /></label>
                <label className="block text-sm">{t('finance.fields.everyMonths')}<input required type="number" min="1" max="12" className="mt-1 w-full rounded border p-2" value={adding.every} onChange={set('every')} /></label>
                <div className="space-y-2 rounded border p-3">
                  <p className="text-sm font-medium">{t('finance.firstPaymentTitle')}</p>
                  <label className="block text-sm">{t('finance.fields.firstPayment')}<input type="number" min="0" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={adding.first_payment} onChange={set('first_payment')} /></label>
                  {adding.first_payment && (
                    <>
                      <label className="block text-sm">{t('finance.fields.method')}
                        <select className="mt-1 w-full rounded border p-2" value={adding.method} onChange={set('method')}>{['cash', 'bank_transfer', 'card', 'cheque', 'online'].map((m) => <option key={m} value={m}>{t(`finance.methods.${m}`)}</option>)}</select>
                      </label>
                      <label className="block text-sm">{t('finance.fields.payerName')}<input className="mt-1 w-full rounded border p-2" value={adding.payer} onChange={set('payer')} /></label>
                    </>
                  )}
                </div>
              </>
            )}
            <div className="flex justify-end gap-2">
              <button type="button" className="rounded border px-3 py-1.5" onClick={() => setAdding(null)}>{t('admin.actions.cancel')}</button>
              <button type="submit" className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50" disabled={addFee.isPending}>{t('admin.actions.save')}</button>
            </div>
          </form>
        </Modal>
      )}
    </section>
  )
}
