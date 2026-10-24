import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'

const today = () => new Date().toISOString().slice(0, 10)

// Teacher: write what today's lesson covered (any past day too); writing again for the same section, subject and day replaces it.
export default function DailySummaryPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [pair, setPair] = useState('')
  const [date, setDate] = useState(today())
  const [text, setText] = useState(null) // null = show the saved text of that day
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const [removing, setRemoving] = useState(null)
  const subject = (s) => s[`name_${i18n.language}`] || s.name_ar

  const options = useQuery({ queryKey: ['daily-summaries', 'options'], queryFn: async () => (await api.get('/grading/options')).data.data.assignments })
  const mine = useQuery({ queryKey: ['daily-summaries', 'mine'], queryFn: async () => (await api.get('/daily-summaries')).data.data })

  const first = options.data?.[0]
  const active = pair || (first ? `${first.classroom.id}-${first.subject.id}` : '')
  const [classroomId, subjectId] = active ? active.split('-').map(Number) : [null, null]
  const existing = (mine.data ?? []).find((s) => s.classroom.id === classroomId && s.subject.id === subjectId && s.date === date)
  const value = text ?? existing?.summary ?? ''

  const refresh = () => client.invalidateQueries({ queryKey: ['daily-summaries'] })
  const save = useMutation({
    mutationFn: () => api.put('/daily-summaries', { classroom_id: classroomId, subject_id: subjectId, date, summary: value }),
    onSuccess: () => { setError(''); setSaved(true); setText(null); refresh() },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')),
  })
  const remove = useMutation({ mutationFn: (id) => api.delete(`/daily-summaries/${id}`), onSuccess: () => { setRemoving(null); setText(null); refresh() } })

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('dailySummary.title')}</h1>
      <p className="text-sm text-slate-600">{t('dailySummary.help')}</p>
      <form className="space-y-3 rounded-lg bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <div className="flex flex-wrap gap-3">
          <label className="text-sm">{t('homework.classAndSubject')}
            <select className="mt-1 block rounded border p-2" value={active} onChange={(e) => { setPair(e.target.value); setText(null); setSaved(false) }}>
              {(options.data ?? []).map((a) => <option key={`${a.classroom.id}-${a.subject.id}`} value={`${a.classroom.id}-${a.subject.id}`}>{`${a.classroom.name} · ${subject(a.subject)}`}</option>)}
            </select>
          </label>
          <label className="text-sm">{t('behavior.date')}
            <input type="date" max={today()} className="mt-1 block rounded border p-2" value={date} onChange={(e) => { setDate(e.target.value || today()); setText(null); setSaved(false) }} />
          </label>
        </div>
        <label className="block text-sm">{t('dailySummary.summary')}
          <textarea required rows={5} className="mt-1 w-full rounded border p-2" value={value} onChange={(e) => { setText(e.target.value); setSaved(false) }} />
        </label>
        {existing && <p className="text-xs text-slate-500">{t('dailySummary.replaces')}</p>}
        <div className="flex items-center gap-3">
          <button type="submit" className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50" disabled={!active || save.isPending}>{t('admin.actions.save')}</button>
          {saved && <span role="status" className="text-sm text-green-700">{t('dailySummary.saved')}</span>}
        </div>
      </form>

      <h2 className="font-bold">{t('dailySummary.previous')}</h2>
      {mine.isSuccess && mine.data.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('dailySummary.noneWritten')}</p>}
      <ul className="space-y-2">
        {(mine.data ?? []).map((s) => (
          <li key={s.id} className="rounded border bg-white p-3">
            <p className="text-sm font-medium"><span dir="ltr">{s.date}</span> · {s.classroom.name} · {subject(s.subject)}</p>
            <p className="whitespace-pre-line text-sm text-slate-700">{s.summary}</p>
            <button type="button" className="text-xs text-red-700 underline" onClick={() => setRemoving(s)}>{t('admin.actions.delete')}</button>
          </li>
        ))}
      </ul>
      <ConfirmDialog open={Boolean(removing)} message={t('dailySummary.confirmDelete', { date: removing?.date })} onConfirm={() => remove.mutate(removing.id)} onCancel={() => setRemoving(null)} />
    </section>
  )
}
