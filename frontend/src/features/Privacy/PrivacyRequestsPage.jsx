import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import Modal from '../../components/shared/Modal'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const STYLE = { pending: 'bg-amber-100 text-amber-800', done: 'bg-green-100 text-green-800', refused: 'bg-red-100 text-red-700' }

function AnswerDialog({ row, status, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [answer, setAnswer] = useState('')
  const [error, setError] = useState('')
  const save = useMutation({ mutationFn: () => api.put(`/privacy/requests/${row.id}`, { status, answer }), onSuccess: () => { client.invalidateQueries({ queryKey: ['privacy'] }); onClose() }, onError: (err) => setError(errorOf(err, t('admin.actions.error'))) })
  return (
    <Modal open title={t(status === 'done' ? 'privacy.admin.doneTitle' : 'privacy.admin.refuseTitle', { name: row.user?.name })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t(status === 'done' ? 'privacy.admin.whatDone' : 'privacy.admin.whyRefused')}<textarea required rows={3} className="mt-1 w-full rounded border p-2" value={answer} onChange={(e) => setAnswer(e.target.value)} /></label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <InteractiveHoverButton type="submit" disabled={save.isPending} className="px-4 py-1.5 disabled:opacity-50">{t('privacy.admin.send')}</InteractiveHoverButton>
        </div>
      </form>
    </Modal>
  )
}

// The principal answers the requests to delete or correct personal data. Records the law makes the school keep (payments) are refused with a reason.
export default function PrivacyRequestsPage() {
  const { t, i18n } = useTranslation()
  const [dialog, setDialog] = useState(null)
  const { data } = useQuery({ queryKey: ['privacy', 'all'], queryFn: async () => (await api.get('/privacy/requests/all')).data })
  const nameOf = (s) => (s ? s[`name_${i18n.language}`] || s.name_ar : '')
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('privacy.admin.title')}</h1>
      <p className="text-sm text-slate-600">{t('privacy.admin.help')}</p>
      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : data.data.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('privacy.none')}</p> : (
        <ul className="space-y-3">
          {data.data.map((r) => (
            <li key={r.id} className="space-y-2 rounded-lg border bg-white p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="font-bold">{t(`privacy.types.${r.type}`)} · <bdi>{r.user?.name}</bdi> <span className="text-sm font-normal text-slate-500">({t(`roles.${r.user?.role}`)})</span></p>
                  {r.student && <p className="text-sm">{t('privacy.admin.aboutStudent')}: <bdi>{nameOf(r.student)}</bdi></p>}
                  <p className="text-xs text-slate-500" dir="ltr">{r.created_at?.slice(0, 10)}</p>
                </div>
                <span className={`rounded-full px-2 py-0.5 text-xs ${STYLE[r.status]}`}>{t(`privacy.status.${r.status}`)}</span>
              </div>
              {r.message && <p className="rounded bg-slate-50 p-2 text-sm">{r.message}</p>}
              {r.answer && <p className="text-sm"><b>{t('privacy.answer')}:</b> {r.answer}</p>}
              {r.status === 'pending' && (
                <div className="flex gap-2">
                  <button type="button" className="rounded bg-green-700 px-4 py-1.5 text-white" onClick={() => setDialog({ row: r, status: 'done' })}>{t('privacy.admin.markDone')}</button>
                  <button type="button" className="rounded border border-red-300 px-4 py-1.5 text-red-700" onClick={() => setDialog({ row: r, status: 'refused' })}>{t('privacy.admin.refuse')}</button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
      {dialog && <AnswerDialog row={dialog.row} status={dialog.status} onClose={() => setDialog(null)} />}
    </section>
  )
}
