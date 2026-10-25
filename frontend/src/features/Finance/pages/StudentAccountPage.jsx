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
  const [error, setError] = useState('')

  const statement = useQuery({ queryKey: ['finance', 'student', id], queryFn: async () => (await api.get(`/finance/students/${id}`)).data.data })
  const settings = useQuery({ queryKey: ['finance', 'settings'], queryFn: async () => (await api.get('/finance/settings')).data.data })
  const canManage = settings.data?.can_manage
  const refresh = () => client.invalidateQueries({ queryKey: ['finance'] })
  const fail = (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error'))

  const voidIt = useMutation({ mutationFn: (pid) => api.delete(`/finance/payments/${pid}`), onSuccess: () => { setVoiding(null); refresh() }, onError: (e) => { setVoiding(null); fail(e) } })
  const deleteFee = useMutation({ mutationFn: (fid) => api.delete(`/finance/fees/${fid}`), onSuccess: () => { setDeleting(null); refresh() }, onError: (e) => { setDeleting(null); fail(e) } })
  const addFee = useMutation({
    mutationFn: () => api.post(`/finance/students/${id}/fees`, { description: adding.description, total_amount: adding.total, discount: adding.discount || 0, installments_count: Number(adding.count), first_due: adding.first_due, every_months: Number(adding.every) }),
    onSuccess: () => { setAdding(null); setError(''); refresh() }, onError: fail,
  })

  if (statement.isLoading) return <p>{t('common.loading')}</p>
  const data = statement.data
  const student = data.student
  const set = (key) => (e) => setAdding({ ...adding, [key]: e.target.value })

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{student[`name_${i18n.language}`] || student.name_ar} <span className="text-sm font-normal text-slate-500" dir="ltr">{student.student_number}</span></h1>
        {canManage && <button type="button" className="rounded border px-4 py-1.5 text-brand" onClick={() => { setError(''); setAdding({ description: '', total: '', discount: '0', count: '1', first_due: '', every: '1' }) }}>{t('finance.addFee')}</button>}
      </div>
      {error && !adding && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      <Statement
        data={data}
        onPay={canManage ? (fee) => setPaying(fee) : null} onReceipt={canManage ? (p, fee) => setReceipt({ payment: p, fee }) : null}
        onVoid={canManage ? (p) => setVoiding(p) : null} onDeleteFee={canManage ? (fee) => setDeleting(fee) : null}
      />

      {paying && <PaymentDialog fee={paying} currency={data.currency} onClose={() => setPaying(null)} onDone={(payment) => { setPaying(null); setReceipt({ payment, fee: paying }) }} />}
      {receipt && <ReceiptDialog payment={receipt.payment} fee={receipt.fee} student={student} currency={data.currency} onClose={() => setReceipt(null)} />}
      <ConfirmDialog open={Boolean(voiding)} message={t('finance.confirmVoid', { receipt: voiding?.receipt })} confirmLabel={t('finance.voidPayment')} onConfirm={() => voidIt.mutate(voiding.id)} onCancel={() => setVoiding(null)} />
      <ConfirmDialog open={Boolean(deleting)} message={t('finance.confirmDeleteFee', { name: deleting?.description })} onConfirm={() => deleteFee.mutate(deleting.id)} onCancel={() => setDeleting(null)} />
      {adding && (
        <Modal open title={t('finance.addFee')} onClose={() => setAdding(null)}>
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); addFee.mutate() }}>
            {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
            <label className="block text-sm">{t('finance.fields.description')}<input required className="mt-1 w-full rounded border p-2" value={adding.description} onChange={set('description')} /></label>
            <label className="block text-sm">{t('finance.fields.total')}<input required type="number" min="0.01" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={adding.total} onChange={set('total')} /></label>
            <label className="block text-sm">{t('finance.fields.discount')}<input type="number" min="0" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={adding.discount} onChange={set('discount')} /></label>
            <label className="block text-sm">{t('finance.fields.installments')}<input required type="number" min="1" max="24" className="mt-1 w-full rounded border p-2" value={adding.count} onChange={set('count')} /></label>
            <label className="block text-sm">{t('finance.fields.firstDue')}<input required type="date" className="mt-1 w-full rounded border p-2" value={adding.first_due} onChange={set('first_due')} /></label>
            <label className="block text-sm">{t('finance.fields.everyMonths')}<input required type="number" min="1" max="12" className="mt-1 w-full rounded border p-2" value={adding.every} onChange={set('every')} /></label>
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
