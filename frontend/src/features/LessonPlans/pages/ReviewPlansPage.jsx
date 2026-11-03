import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import PlanCard from '../components/PlanCard'

// Admin: the plans the teachers submitted. Approve, or reject with a note the teacher sees.
export default function ReviewPlansPage() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [rejecting, setRejecting] = useState(null)
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const list = useQuery({ queryKey: ['lesson-plans', 'review'], queryFn: async () => (await api.get('/lesson-plans/review')).data.data })

  const decide = useMutation({
    mutationFn: ({ id, decision, text }) => api.post(`/lesson-plans/${id}/review`, { decision, note: text || null }),
    onSuccess: () => { setRejecting(null); setNote(''); setError(''); client.invalidateQueries({ queryKey: ['lesson-plans'] }) },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')),
  })

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('lessonPlans.reviewTitle')}</h1>
      {list.isSuccess && list.data.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('lessonPlans.nothingToReview')}</p>}
      <ul className="space-y-3">
        {(list.data ?? []).map((p) => (
          <PlanCard key={p.id} plan={p}>
            <div className="flex gap-2 pt-1">
              <button type="button" className="rounded bg-green-700 px-3 py-1 text-sm text-white" onClick={() => decide.mutate({ id: p.id, decision: 'approve' })}>{t('lessonPlans.approve')}</button>
              <button type="button" className="rounded border border-red-300 px-3 py-1 text-sm text-red-700" onClick={() => { setError(''); setRejecting(p) }}>{t('lessonPlans.reject')}</button>
            </div>
          </PlanCard>
        ))}
      </ul>
      {rejecting && (
        <Modal open title={t('lessonPlans.rejectTitle', { title: rejecting.title })} onClose={() => setRejecting(null)}>
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); decide.mutate({ id: rejecting.id, decision: 'reject', text: note }) }}>
            {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
            <label className="block text-sm">{t('lessonPlans.rejectNote')}<textarea required rows={4} className="mt-1 w-full rounded border p-2" value={note} onChange={(e) => setNote(e.target.value)} /></label>
            <div className="flex justify-end gap-2">
              <button type="button" className="rounded border px-3 py-1.5" onClick={() => setRejecting(null)}>{t('admin.actions.cancel')}</button>
              <button type="submit" className="rounded bg-red-600 px-4 py-1.5 text-white">{t('lessonPlans.reject')}</button>
            </div>
          </form>
        </Modal>
      )}
    </section>
  )
}
