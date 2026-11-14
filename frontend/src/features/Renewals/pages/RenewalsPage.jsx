import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const STATUS_STYLE = { pending: 'bg-amber-100 text-amber-800', renewed: 'bg-green-100 text-green-800', declined: 'bg-red-100 text-red-700', graduating: 'bg-slate-100 text-slate-600' }

// Renewing one student: the section they move to in the new year (and the fees that come with it).
function RenewDialog({ row, year, onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [repeat, setRepeat] = useState(row.to_grade === null)
  const [form, setForm] = useState({ classroom_id: '', discount: '', transport_fee: '' })
  const [error, setError] = useState('')
  const gradeName = (g) => (g ? g[`name_${i18n.language}`] || g.name_ar : '')
  const gradeId = repeat ? row.from_grade?.id : row.to_grade?.id
  const sections = useQuery({ queryKey: ['renewals', 'sections', year.id, gradeId], queryFn: async () => (await api.get('/renewals/sections', { params: { year_id: year.id, grade_level_id: gradeId } })).data.data, enabled: !!gradeId })
  const save = useMutation({
    mutationFn: () => api.post(`/renewals/${row.id}/renew`, {
      classroom_id: form.classroom_id || null, grade_level_id: repeat ? row.from_grade?.id : null,
      discount: form.discount || null, transport_fee: form.transport_fee || null,
    }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['renewals'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const name = row.student[`name_${i18n.language}`] || row.student.name_ar
  return (
    <Modal open title={t('renewals.renewFor', { name })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <p className="text-sm text-slate-600">{t('renewals.moveInfo', { from: gradeName(row.from_grade), to: repeat ? gradeName(row.from_grade) : gradeName(row.to_grade) || '—', year: year.name })}</p>
        {row.from_grade && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={repeat} onChange={(e) => { setRepeat(e.target.checked); setForm({ ...form, classroom_id: '' }) }} />{t('renewals.repeatGrade')}</label>}
        <label className="block text-sm">{t('renewals.section')}
          <select className="mt-1 w-full rounded border p-2" value={form.classroom_id} onChange={(e) => setForm({ ...form, classroom_id: e.target.value })}>
            <option value="">{t('renewals.autoSection')}</option>
            {(sections.data ?? []).map((s) => <option key={s.id} value={s.id} disabled={s.free !== null && s.free <= 0}>{s.name} ({s.students}{s.capacity ? `/${s.capacity}` : ''})</option>)}
          </select>
        </label>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{t('renewals.discount')}<input type="number" step="0.01" min="0" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.discount} onChange={(e) => setForm({ ...form, discount: e.target.value })} /></label>
          <label className="block text-sm">{t('renewals.transport')}<input type="number" step="0.01" min="0" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.transport_fee} onChange={(e) => setForm({ ...form, transport_fee: e.target.value })} /></label>
        </div>
        <p className="text-xs text-slate-500">{t('renewals.feesHint')}</p>
        <div className="flex justify-end gap-2">
          <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</InteractiveHoverButton>
          <InteractiveHoverButton type="submit" disabled={save.isPending || !gradeId} className="px-4 py-1.5 disabled:opacity-50">{t('renewals.renew')}</InteractiveHoverButton>
        </div>
      </form>
    </Modal>
  )
}

function DeclineDialog({ row, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => api.post(`/renewals/${row.id}/decline`, { reason: reason || null }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['renewals'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  return (
    <Modal open title={t('renewals.declineFor', { name: row.student.name_ar })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('renewals.reason')}<input className="mt-1 w-full rounded border p-2" value={reason} onChange={(e) => setReason(e.target.value)} /></label>
        <div className="flex justify-end gap-2">
          <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</InteractiveHoverButton>
          <button type="submit" disabled={save.isPending} className="rounded bg-red-700 px-4 py-1.5 text-white disabled:opacity-50">{t('renewals.decline')}</button>
        </div>
      </form>
    </Modal>
  )
}

// The yearly re-registration: open it for the next year, then renew each student (they move to the next grade) or decline.
export default function RenewalsPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [yearId, setYearId] = useState('')
  const [filter, setFilter] = useState('pending')
  const [search, setSearch] = useState('')
  const [dialog, setDialog] = useState(null)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const { data, isLoading } = useQuery({ queryKey: ['renewals', yearId], queryFn: async () => (await api.get('/renewals', { params: yearId ? { year_id: yearId } : {} })).data })
  const refresh = () => client.invalidateQueries({ queryKey: ['renewals'] })
  const fail = (err) => setError(errorOf(err, t('admin.actions.error')))
  const open = useMutation({ mutationFn: () => api.post('/renewals/open', { year_id: data.year.id }), onSuccess: (res) => { setError(''); setMessage(t('renewals.opened', { count: res.data.added })); refresh() }, onError: fail })
  const all = useMutation({
    mutationFn: () => api.post('/renewals/renew-all', { year_id: data.year.id }),
    onSuccess: (res) => { setError(''); setMessage(t('renewals.renewedAll', { count: res.data.renewed, failed: res.data.failed.length }) + res.data.failed.map((f) => `\n• ${f.student}: ${f.reason}`).join('')); refresh() },
    onError: fail,
  })
  const reopen = useMutation({ mutationFn: (id) => api.post(`/renewals/${id}/reopen`), onSuccess: refresh, onError: fail })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  const gradeName = (g) => (g ? g[`name_${i18n.language}`] || g.name_ar : '—')
  const q = search.trim().toLowerCase()
  const shown = data.data.filter((r) => (filter === 'all' || r.status === filter) && (!q || `${r.student.name_ar} ${r.student.name_en ?? ''} ${r.student.student_number}`.toLowerCase().includes(q)))

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('renewals.title')}</h1>
        <label className="text-sm">{t('renewals.forYear')}
          <select className="ms-2 rounded border p-1.5" value={data.year?.id ?? ''} onChange={(e) => setYearId(e.target.value)}>
            {data.years.filter((y) => !y.is_current).map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
          </select>
        </label>
      </div>
      <p className="text-sm text-slate-600">{t('renewals.help')}</p>
      {!data.year && <p className="rounded border bg-white p-4 text-center text-slate-500">{t('renewals.noYear')}</p>}
      {message && <p role="status" className="whitespace-pre-line rounded bg-green-50 p-2 text-sm text-green-800">{message}</p>}
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      {data.year && (
        <div className="flex flex-wrap items-center gap-2">
          <InteractiveHoverButton variant="outline" type="button" disabled={open.isPending} className="px-3 py-1.5 text-brand" onClick={() => open.mutate()}>{data.summary.total > 0 ? t('renewals.addMissing') : t('renewals.open')}</InteractiveHoverButton>
          {data.summary.pending > 0 && <InteractiveHoverButton type="button" disabled={all.isPending} className="px-3 py-1.5 disabled:opacity-50" onClick={() => all.mutate()}>{t('renewals.renewAll', { count: data.summary.pending })}</InteractiveHoverButton>}
        </div>
      )}

      {data.summary.total > 0 && (
        <>
          <div className="flex flex-wrap items-center gap-2" role="tablist">
            {['pending', 'renewed', 'declined', 'graduating', 'all'].map((k) => (
              <button key={k} type="button" role="tab" aria-selected={filter === k} className={`rounded-full border px-3 py-1 text-sm ${filter === k ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => setFilter(k)}>{t(`renewals.status.${k}`)} ({k === 'all' ? data.summary.total : data.summary[k]})</button>
            ))}
            <input type="search" aria-label={t('admin.actions.search')} placeholder={t('admin.actions.search')} className="ms-auto rounded border p-1.5 text-sm" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
          <div className="overflow-x-auto rounded-lg border bg-white">
            <table className="w-full text-start text-sm">
              <thead className="bg-slate-50"><tr>{['student', 'from', 'to', 'state'].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`renewals.cols.${c}`)}</th>)}<th><span className="sr-only">{t('a11y.actions')}</span></th></tr></thead>
              <tbody>
                {shown.map((r) => (
                  <tr key={r.id} className="border-t align-top">
                    <td className="p-2 font-medium">{r.student[`name_${i18n.language}`] || r.student.name_ar}<span className="block text-xs font-normal text-slate-500" dir="ltr">{r.student.student_number}</span></td>
                    <td className="p-2 text-slate-600">{gradeName(r.from_grade)}{r.from_classroom ? ` · ${r.from_classroom}` : ''}</td>
                    <td className="p-2 text-slate-600">{r.status === 'graduating' ? t('renewals.lastGrade') : gradeName(r.to_grade)}{r.to_classroom ? ` · ${r.to_classroom}` : ''}</td>
                    <td className="p-2"><span className={`rounded-full px-2 py-0.5 text-xs ${STATUS_STYLE[r.status]}`}>{t(`renewals.status.${r.status}`)}</span>{r.decline_reason && <span className="block text-xs text-slate-500">{r.decline_reason}</span>}</td>
                    <td className="p-2">
                      <span className="flex flex-wrap justify-end gap-2">
                        {['pending', 'graduating'].includes(r.status) && <InteractiveHoverButton type="button" className="px-3 py-1" onClick={() => setDialog({ type: 'renew', row: r })}>{t('renewals.renew')}</InteractiveHoverButton>}
                        {['pending', 'graduating'].includes(r.status) && <button type="button" className="rounded border border-red-300 px-3 py-1 text-red-700" onClick={() => setDialog({ type: 'decline', row: r })}>{t('renewals.decline')}</button>}
                        {r.status === 'declined' && <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1 text-brand" onClick={() => reopen.mutate(r.id)}>{t('renewals.reopen')}</InteractiveHoverButton>}
                      </span>
                    </td>
                  </tr>
                ))}
                {shown.length === 0 && <tr><td colSpan={5} className="p-4 text-center text-slate-500">{t('renewals.noRows')}</td></tr>}
              </tbody>
            </table>
          </div>
        </>
      )}
      {dialog?.type === 'renew' && <RenewDialog row={dialog.row} year={data.year} onClose={() => setDialog(null)} />}
      {dialog?.type === 'decline' && <DeclineDialog row={dialog.row} onClose={() => setDialog(null)} />}
    </section>
  )
}
