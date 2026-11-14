import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { AttachmentList } from '../../../components/shared/Attachments'
import Money from '../components/Money'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const STYLE = { pending: 'bg-amber-100 text-amber-800', paid: 'bg-green-100 text-green-800', failed: 'bg-red-100 text-red-700', review: 'bg-amber-100 text-amber-800' }

function RejectDialog({ row, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const reject = useMutation({ mutationFn: () => api.post(`/finance/transfers/${row.id}/reject`, { note }), onSuccess: () => { client.invalidateQueries({ queryKey: ['finance'] }); onClose() }, onError: (err) => setError(errorOf(err, t('admin.actions.error'))) })
  return (
    <Modal open title={t('transfers.rejectTitle', { name: row.payer_name })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); reject.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('transfers.reason')}<textarea required rows={2} className="mt-1 w-full rounded border p-2" value={note} onChange={(e) => setNote(e.target.value)} /></label>
        <div className="flex justify-end gap-2">
          <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</InteractiveHoverButton>
          <button type="submit" disabled={reject.isPending} className="rounded bg-red-600 px-4 py-1.5 text-white disabled:opacity-50">{t('transfers.reject')}</button>
        </div>
      </form>
    </Modal>
  )
}

// Bank transfers and CliQ payments the parents reported: look at the proof, then confirm (the payment is recorded) or refuse with a reason.
export default function TransfersPage({ base }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [rejecting, setRejecting] = useState(null)
  const [error, setError] = useState('')
  const { data } = useQuery({ queryKey: ['finance', 'transfers'], queryFn: async () => (await api.get('/finance/transfers')).data })
  const settings = useQuery({ queryKey: ['finance', 'settings'], queryFn: async () => (await api.get('/finance/settings')).data.data })
  const approve = useMutation({ mutationFn: (row) => api.post(`/finance/transfers/${row.id}/approve`), onSuccess: () => client.invalidateQueries({ queryKey: ['finance'] }), onError: (err) => setError(errorOf(err, t('admin.actions.error'))) })
  const nameOf = (s) => (s ? s[`name_${i18n.language}`] || s.name_ar : '')
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('transfers.title')}</h1>
        <Link to={`${base}/finance`} className="rounded border px-3 py-1.5 text-sm text-brand">{t('finance.title')}</Link>
      </div>
      <p className="text-sm text-slate-600">{t('transfers.help')}</p>
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : data.data.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('transfers.none')}</p> : (
        <ul className="space-y-3">
          {data.data.map((r) => (
            <li key={r.id} className="space-y-2 rounded-lg border bg-white p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="font-bold"><Money value={r.amount} currency={settings.data?.currency ?? ''} /> · <bdi>{nameOf(r.student)}</bdi></p>
                  <p className="text-sm text-slate-600">{t('transfers.from', { name: r.payer_name })} · {r.fee}</p>
                  <p className="text-sm">{t('transfers.reference')}: <bdi dir="ltr" className="font-mono">{r.transfer_reference}</bdi></p>
                </div>
                <span className={`rounded-full px-2 py-0.5 text-xs ${STYLE[r.status]}`}>{t(`transfers.status.${r.status}`)}{r.receipt ? ` · ${r.receipt}` : ''}</span>
              </div>
              {r.review_note && <p className="text-sm text-red-700">{r.review_note}</p>}
              <AttachmentList items={r.attachments} />
              {r.attachments.length === 0 && r.status === 'pending' && <p className="text-xs text-amber-700">{t('transfers.noProof')}</p>}
              {r.status === 'pending' && (
                <div className="flex gap-2">
                  <button type="button" disabled={approve.isPending} className="rounded bg-green-700 px-4 py-1.5 text-white disabled:opacity-50" onClick={() => { setError(''); approve.mutate(r) }}>{t('transfers.approve')}</button>
                  <button type="button" className="rounded border border-red-300 px-4 py-1.5 text-red-700" onClick={() => setRejecting(r)}>{t('transfers.reject')}</button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
      {rejecting && <RejectDialog row={rejecting} onClose={() => setRejecting(null)} />}
    </section>
  )
}
