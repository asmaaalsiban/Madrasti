import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { DateInput } from '../../components/shared/DateInput'
import Modal from '../../components/shared/Modal'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

function EditDialog({ session, onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const staff = useQuery({ queryKey: ['payroll', 'staff'], queryFn: async () => (await api.get('/payroll/staff')).data })
  const byPeriods = session.period_from !== null
  const [form, setForm] = useState({ exam_date: session.date, starts_at: session.starts_at, ends_at: session.ends_at, period_from: session.period_from ?? '', period_to: session.period_to ?? '' })
  const [mode, setMode] = useState(byPeriods ? 'periods' : 'time')
  const [watchers, setWatchers] = useState(session.invigilators.map((u) => u.id))
  const [touched, setTouched] = useState(false)
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => api.put(`/exams/${session.id}`, mode === 'periods'
      ? { exam_date: form.exam_date, period_from: Number(form.period_from), period_to: Number(form.period_to || form.period_from), ...(touched ? { invigilator_ids: watchers } : {}) }
      : { exam_date: form.exam_date, starts_at: form.starts_at, ends_at: form.ends_at, invigilator_ids: watchers }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['exams'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const drop = useMutation({ mutationFn: () => api.delete(`/exams/${session.id}`), onSuccess: () => { client.invalidateQueries({ queryKey: ['exams'] }); onClose() } })
  const subject = session.subject[`name_${i18n.language}`] || session.subject.name_ar
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const teachers = (staff.data?.data ?? []).filter((s) => ['teacher', 'head_of_department', 'subject_coordinator', 'trainee', 'substitute_teacher'].includes(s.staff_type))
  return (
    <Modal open size="lg" title={`${subject} · ${session.classroom.name}`} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <div className="grid gap-3 sm:grid-cols-3">
          <label className="block text-sm">{t('exams.date')}<DateInput required className="mt-1 w-full rounded border p-2" value={form.exam_date} onChange={set('exam_date')} /></label>
          <label className="block text-sm sm:col-span-2">{t('exams.when')}
            <select className="mt-1 w-full rounded border p-2" value={mode} onChange={(e) => setMode(e.target.value)}>
              <option value="periods">{t('exams.byPeriods')}</option>
              <option value="time">{t('exams.byTime')}</option>
            </select>
          </label>
        </div>
        {mode === 'periods' ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm">{t('exams.periodFrom')}<input required type="number" min="1" max="12" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.period_from} onChange={set('period_from')} /></label>
            <label className="block text-sm">{t('exams.periodTo')}<input type="number" min="1" max="12" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.period_to} onChange={set('period_to')} /></label>
            <p className="text-xs text-slate-500 sm:col-span-2">{t('exams.periodsMoveHelp')}</p>
          </div>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm">{t('exams.startsAt')}<input required type="time" className="mt-1 w-full rounded border p-2" value={form.starts_at} onChange={set('starts_at')} /></label>
            <label className="block text-sm">{t('exams.endsAt')}<input required type="time" className="mt-1 w-full rounded border p-2" value={form.ends_at} onChange={set('ends_at')} /></label>
          </div>
        )}
        <div>
          <p className="mb-1 text-sm font-medium">{t('exams.watchers')}</p>
          <ul className="grid max-h-44 gap-1 overflow-y-auto rounded border p-2 sm:grid-cols-2">
            {teachers.map((s) => <li key={s.id}><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={watchers.includes(s.id)} onChange={() => { setTouched(true); setWatchers(watchers.includes(s.id) ? watchers.filter((x) => x !== s.id) : [...watchers, s.id]) }} />{s.name}</label></li>)}
          </ul>
        </div>
        <div className="flex justify-between gap-2">
          <button type="button" className="rounded border border-red-300 px-3 py-1.5 text-red-700" onClick={() => window.confirm(t('exams.deleteConfirm')) && drop.mutate()}>{t('admin.actions.delete')}</button>
          <span className="flex gap-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
            <button type="submit" disabled={save.isPending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
          </span>
        </div>
      </form>
    </Modal>
  )
}

// The exam timetable: set the period and the rules, preview, publish; then fix single exams by hand.
export default function ExamsAdminPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const terms = useQuery({ queryKey: ['terms', 'all'], queryFn: async () => (await api.get('/terms', { params: { per_page: 100 } })).data.data })
  const grades = useQuery({ queryKey: ['grade-levels', 'all'], queryFn: async () => (await api.get('/grade-levels', { params: { per_page: 100 } })).data.data })
  const [form, setForm] = useState({ term_id: '', from: '', to: '', first_period: 2, periods_per_exam: 2, slots_per_day: 1, grade_per_day: 1 })
  const [only, setOnly] = useState([])
  const [fill, setFill] = useState(true)
  const [off, setOff] = useState([])
  const [offDraft, setOffDraft] = useState('')
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const [edit, setEdit] = useState(null)
  const [gradeFilter, setGradeFilter] = useState('')
  // Until a term is chosen the server says which one is meant (the running or the next one).
  const list = useQuery({ queryKey: ['exams', 'admin', form.term_id, gradeFilter], queryFn: async () => (await api.get('/exams', { params: { ...(form.term_id ? { term_id: form.term_id } : {}), ...(gradeFilter ? { grade_level_id: gradeFilter } : {}) } })).data })
  const termId = form.term_id || String(list.data?.term_id ?? terms.data?.[0]?.id ?? '')
  const body = (dry) => ({ ...form, term_id: Number(termId), fill_missing: fill, exclude_dates: off, grade_level_ids: only.length ? only : null, dry_run: dry })
  const run = useMutation({
    mutationFn: (dry) => api.post('/exams/generate', body(dry)),
    onSuccess: (res) => { setError(''); setResult(res.data.data); if (!res.data.data.dry_run) client.invalidateQueries({ queryKey: ['exams'] }) },
    onError: (err) => { setResult(null); setError(errorOf(err, t('admin.actions.error'))) },
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const gradeName = (g) => g[`name_${i18n.language}`] || g.name_ar
  const ready = termId && form.from && form.to

  return (
    <section className="space-y-6">
      <h1>{t('exams.title')}</h1>
      <p className="text-sm text-slate-600">{t('exams.help')}</p>

      <form className="space-y-4 rounded-lg border bg-snow p-5" onSubmit={(e) => { e.preventDefault(); run.mutate(true) }}>
        <div className="grid gap-3 sm:grid-cols-3">
          <label className="block text-sm">{t('exams.term')}
            <select required className="mt-1 w-full rounded border p-2" value={termId} onChange={set('term_id')}>
              {(terms.data ?? []).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
            </select>
          </label>
          <label className="block text-sm">{t('exams.from')}<DateInput required className="mt-1 w-full rounded border p-2" value={form.from} onChange={set('from')} /></label>
          <label className="block text-sm">{t('exams.to')}<DateInput required className="mt-1 w-full rounded border p-2" value={form.to} onChange={set('to')} /></label>
        </div>
        <div className="grid gap-3 sm:grid-cols-4">
          <label className="block text-sm">{t('exams.firstPeriod')}<input required type="number" min="1" max="12" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.first_period} onChange={set('first_period')} /></label>
          <label className="block text-sm">{t('exams.periodsPerExam')}<input required type="number" min="1" max="4" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.periods_per_exam} onChange={set('periods_per_exam')} /></label>
          <label className="block text-sm">{t('exams.slotsPerDay')}<input required type="number" min="1" max="4" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.slots_per_day} onChange={set('slots_per_day')} /></label>
          <label className="block text-sm">{t('exams.gradePerDay')}<input required type="number" min="1" max="4" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.grade_per_day} onChange={set('grade_per_day')} /></label>
        </div>
        <p className="rounded-lg bg-sky-tint/40 p-3 text-sm text-deep-harbor">{t('exams.periodsHelp', { from: form.first_period, to: Number(form.first_period) + Number(form.periods_per_exam) - 1 })}</p>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={fill} onChange={(e) => setFill(e.target.checked)} />{t('exams.fillMissing')}</label>
        <div className="text-sm">
          <p className="mb-1 font-medium">{t('exams.grades')} <span className="font-normal text-slate-500">({t('exams.gradesHint')})</span></p>
          <div className="flex flex-wrap gap-2">
            {(grades.data ?? []).map((g) => (
              <label key={g.id} className={`cursor-pointer rounded-full border px-3 py-1 ${only.includes(g.id) ? 'bg-brand text-white' : 'bg-snow'}`}>
                <input type="checkbox" className="sr-only" checked={only.includes(g.id)} onChange={() => setOnly(only.includes(g.id) ? only.filter((x) => x !== g.id) : [...only, g.id])} />{gradeName(g)}
              </label>
            ))}
          </div>
        </div>
        <div className="text-sm">
          <p className="mb-1 font-medium">{t('exams.holidays')}</p>
          <div className="flex flex-wrap items-center gap-2">
            {off.map((d) => <span key={d} className="rounded-full bg-sky-tint px-3 py-1" dir="ltr">{d}<button type="button" aria-label={t('admin.actions.delete')} className="ms-2 text-red-700" onClick={() => setOff(off.filter((x) => x !== d))}>✕</button></span>)}
            <DateInput aria-label={t('exams.holidays')} className="rounded border p-1.5" value={offDraft} onChange={(e) => setOffDraft(e.target.value)} />
            <button type="button" disabled={!offDraft} className="rounded border px-3 py-1 text-brand disabled:opacity-40" onClick={() => { setOff([...new Set([...off, offDraft])]); setOffDraft('') }}>{t('exams.addHoliday')}</button>
          </div>
        </div>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {result && (
          <div role="status" className={`rounded-lg p-3 text-sm ${result.dry_run ? 'bg-sky-tint/50' : 'bg-green-50 text-green-900'}`}>
            <p className="font-bold">{result.dry_run ? t('exams.previewDone', { sessions: result.sessions, days: result.days }) : t('exams.published', { sessions: result.sessions, days: result.days })}</p>
            {result.warnings.map((w) => <p key={w} className="mt-1 text-amber-800">⚠ {w}</p>)}
          </div>
        )}
        <div className="flex flex-wrap gap-2">
          <button type="submit" disabled={run.isPending || !ready} className="rounded border px-4 py-2 font-semibold text-brand disabled:opacity-50">{t('exams.preview')}</button>
          <button type="button" disabled={run.isPending || !ready} className="rounded bg-brand px-5 py-2 text-white disabled:opacity-50" onClick={() => run.mutate(false)}>{t('exams.publish')}</button>
        </div>
      </form>

      <div className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg">{t('exams.current')}</h2>
          <select aria-label={t('exams.grades')} className="rounded border p-1.5 text-sm" value={gradeFilter} onChange={(e) => setGradeFilter(e.target.value)}>
            <option value="">{t('exams.allGrades')}</option>
            {(grades.data ?? []).map((g) => <option key={g.id} value={g.id}>{gradeName(g)}</option>)}
          </select>
        </div>
        {list.data ? <AdminList sessions={list.data.data} onEdit={setEdit} /> : <p className="text-slate-500">{t('common.loading')}</p>}
      </div>
      {edit && <EditDialog session={edit} onClose={() => setEdit(null)} />}
    </section>
  )
}

// Exams by day with an edit button on each.
function AdminList({ sessions, onEdit }) {
  const { t, i18n } = useTranslation()
  if (sessions.length === 0) return <p className="rounded-lg border bg-snow p-6 text-center text-slate-600">{t('exams.none')}</p>
  const days = [...new Set(sessions.map((s) => s.date))]
  const subject = (s) => s.subject[`name_${i18n.language}`] || s.subject.name_ar
  return (
    <div className="space-y-3">
      {days.map((d) => (
        <section key={d} className="overflow-hidden rounded-lg border bg-snow">
          <h3 className="bg-slate-50 px-4 py-2 text-sm" dir="ltr">{d}</h3>
          <ul className="divide-y">
            {sessions.filter((s) => s.date === d).map((s) => (
              <li key={s.id} className="flex flex-wrap items-center gap-3 px-4 py-2 text-sm">
                <span className="w-44 text-slate-600"><span dir="ltr">{s.starts_at} – {s.ends_at}</span>{s.period_from !== null && <span className="ms-2 text-xs text-slate-400">{s.period_from === s.period_to ? t('exams.periodOne', { n: s.period_from }) : t('exams.periodsRange', { from: s.period_from, to: s.period_to })}</span>}</span>
                <span className="font-bold text-deep-harbor">{subject(s)}</span>
                <span className="rounded-full bg-sky-tint px-3 py-0.5 text-xs">{s.classroom.name}</span>
                <span className="min-w-0 flex-1 truncate text-xs text-slate-500">{s.invigilators.map((u) => u.name).join('، ')}</span>
                <button type="button" className="rounded border px-3 py-1 text-brand" onClick={() => onEdit(s)}>{t('admin.actions.edit')}</button>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  )
}
