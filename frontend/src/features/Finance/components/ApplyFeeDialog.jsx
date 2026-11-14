import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { DateInput } from '../../../components/shared/DateInput'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// Gives a structure's fee to every student of its grade, split into equal installments.
export default function ApplyFeeDialog({ structure, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ installments: String(structure.installments_count ?? 4), first_due: structure.first_due ?? '', every_months: String(structure.every_months ?? 1), discount: '0' })
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  const apply = useMutation({
    mutationFn: () => api.post(`/finance/fee-structures/${structure.id}/apply`, { installments: Number(form.installments), first_due: form.first_due, every_months: Number(form.every_months), discount: Number(form.discount || 0) }).then((r) => r.data.data),
    onSuccess: (data) => { setResult(data.students); setError(''); client.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')),
  })

  return (
    <Modal open title={t('finance.applyTitle', { name: structure.name })} onClose={onClose}>
      {result !== null ? (
        <div className="space-y-3">
          <p role="status" className="rounded bg-green-50 p-3 text-green-800">{t('finance.applied', { count: result })}</p>
          <div className="flex justify-end"><button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('homework.close')}</button></div>
        </div>
      ) : (
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); apply.mutate() }}>
          {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
          <p className="text-sm text-slate-600">{t('finance.applyHelp')}</p>
          <label className="block text-sm">{t('finance.fields.installments')}<input required type="number" min="1" max="24" className="mt-1 w-full rounded border p-2" value={form.installments} onChange={set('installments')} /></label>
          <label className="block text-sm">{t('finance.fields.firstDue')}<DateInput required className="mt-1 w-full rounded border p-2" value={form.first_due} onChange={set('first_due')} /></label>
          <label className="block text-sm">{t('finance.fields.everyMonths')}<input required type="number" min="1" max="12" className="mt-1 w-full rounded border p-2" value={form.every_months} onChange={set('every_months')} /></label>
          <label className="block text-sm">{t('finance.fields.discountAll')}<input type="number" min="0" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.discount} onChange={set('discount')} /></label>
          <div className="flex justify-end gap-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
            <InteractiveHoverButton type="submit" className="px-4 py-1.5 disabled:opacity-50" disabled={apply.isPending}>{t('finance.apply')}</InteractiveHoverButton>
          </div>
        </form>
      )}
    </Modal>
  )
}
