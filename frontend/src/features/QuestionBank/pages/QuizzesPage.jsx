import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import QuizHistory from '../components/QuizHistory'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'
import { IconActionButton } from '../../../components/ui/icon-action-button'
import { Undo2 } from 'lucide-react'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// The student picks a subject, some lessons and how many questions, then starts a quiz made from their own grade.
export default function QuizzesPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const lang = i18n.language
  const [subjectId, setSubjectId] = useState('')
  const [picked, setPicked] = useState([])
  const [count, setCount] = useState(10)
  const [timed, setTimed] = useState(false)
  const [minutes, setMinutes] = useState(10)
  const [weak, setWeak] = useState({ subject_id: '', count: '' })
  const [error, setError] = useState('')
  const options = useQuery({ queryKey: ['quizzes', 'options'], queryFn: async () => (await api.get('/quizzes/options')).data })
  const history = useQuery({ queryKey: ['quizzes', 'history'], queryFn: async () => (await api.get('/quizzes')).data.data })
  const subject = (options.data?.data ?? []).find((s) => String(s.id) === String(subjectId))
  const available = (subject?.lessons ?? []).filter((l) => picked.includes(l.id)).reduce((n, l) => n + l.questions, 0)
  const start = useMutation({
    mutationFn: () => api.post('/quizzes', { subject_id: Number(subjectId), lesson_ids: picked, count: Number(count), ...(timed ? { minutes: Number(minutes) } : {}) }),
    onSuccess: (res) => navigate(`/student/quizzes/${res.data.data.id}`, { state: { reduced: res.data.reduced, available: res.data.available } }),
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const practice = useMutation({
    mutationFn: () => api.post('/quizzes', { mode: 'mistakes', ...(weak.subject_id ? { subject_id: Number(weak.subject_id) } : {}), ...(weak.count ? { count: Number(weak.count) } : {}) }),
    onSuccess: (res) => navigate(`/student/quizzes/${res.data.data.id}`),
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const mistakes = options.data?.mistakes ?? 0
  const retake = useMutation({ mutationFn: (id) => api.post(`/quizzes/${id}/retake`), onSuccess: (res) => navigate(`/student/quizzes/${res.data.data.id}`), onError: (err) => setError(errorOf(err, t('admin.actions.error'))) })
  const toggle = (id) => setPicked(picked.includes(id) ? picked.filter((x) => x !== id) : [...picked, id])
  return (
    <section className="space-y-5">
      <h1 className="text-xl font-bold">{t('quiz.title')}</h1>
      <form className="space-y-3 rounded-2xl border bg-white p-4" onSubmit={(e) => { e.preventDefault(); start.mutate() }}>
        <h2 className="font-bold">{t('quiz.newQuiz')}</h2>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {options.data && options.data.data.length === 0 ? <p className="text-slate-500">{t('quiz.noneYet')}</p> : (
          <>
            <label className="block text-sm sm:max-w-sm">{t('quiz.subject')}
              <select required className="mt-1 w-full rounded border p-2" value={subjectId} onChange={(e) => { setSubjectId(e.target.value); setPicked([]) }}>
                <option value="" disabled>—</option>
                {(options.data?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s[`name_${lang}`] || s.name_ar}</option>)}
              </select>
            </label>
            {subject && (
              <fieldset className="text-sm">
                <legend className="mb-1 font-bold">{t('quiz.lessons')}</legend>
                <div className="flex flex-wrap gap-2">
                  {subject.lessons.map((l) => <label key={l.id} className={`flex items-center gap-2 rounded-full border px-3 py-1 ${picked.includes(l.id) ? 'border-brand bg-sky-tint' : ''}`}><input type="checkbox" checked={picked.includes(l.id)} onChange={() => toggle(l.id)} /><span dir="auto">{l.title}</span> <span className="text-xs text-slate-500">({l.questions})</span></label>)}
                </div>
              </fieldset>
            )}
            <label className="block text-sm sm:max-w-[12rem]">{t('quiz.count')}<input required type="number" min="1" max="100" dir="ltr" className="mt-1 w-full rounded border p-2" value={count} onChange={(e) => setCount(e.target.value)} /></label>
            <div className="flex flex-wrap items-center gap-3 text-sm">
              <label className="flex items-center gap-2"><input type="checkbox" checked={timed} onChange={(e) => setTimed(e.target.checked)} />{t('quiz.useTimer')}</label>
              {timed && <label className="flex items-center gap-2">{t('quiz.minutes')}<input type="number" min="1" max="240" dir="ltr" className="w-20 rounded border p-1.5" value={minutes} onChange={(e) => setMinutes(e.target.value)} /></label>}
            </div>
            {picked.length > 0 && Number(count) > available && <p className="text-sm text-amber-700">{t('quiz.onlyAvailable', { n: available })}</p>}
            <InteractiveHoverButton type="submit" disabled={start.isPending || picked.length === 0} className="px-6 py-2 disabled:opacity-50">{t('quiz.generate')}</InteractiveHoverButton>
          </>
        )}
      </form>
      {mistakes > 0 && (
        <form className="space-y-3 rounded-2xl border border-amber-300 bg-amber-50 p-4" onSubmit={(e) => { e.preventDefault(); practice.mutate() }}>
          <h2 className="font-bold">{t('quiz.mistakesTitle')}</h2>
          <p className="text-sm">{t('quiz.mistakesIntro', { n: mistakes })}</p>
          <div className="flex flex-wrap items-end gap-3 text-sm">
            <label>{t('quiz.subject')}
              <select className="mt-1 block rounded border p-1.5" value={weak.subject_id} onChange={(e) => setWeak({ ...weak, subject_id: e.target.value })}>
                <option value="">{t('quiz.allSubjects')}</option>
                {(options.data?.data ?? []).filter((s) => s.mistakes > 0).map((s) => <option key={s.id} value={s.id}>{s[`name_${lang}`] || s.name_ar} ({s.mistakes})</option>)}
              </select>
            </label>
            <label>{t('quiz.countOptional')}<input type="number" min="1" max="100" dir="ltr" className="mt-1 block w-24 rounded border p-1.5" value={weak.count} onChange={(e) => setWeak({ ...weak, count: e.target.value })} /></label>
            <button type="submit" disabled={practice.isPending} className="rounded bg-amber-600 px-5 py-2 text-white disabled:opacity-50">{t('quiz.practiceMistakes')}</button>
          </div>
        </form>
      )}
      <div className="space-y-2">
        <h2 className="font-bold">{t('quiz.history')}</h2>
        <QuizHistory rows={history.data} resumeBase="/student/quizzes" onRetake={(row) => retake.mutate(row.id)} />
      </div>
      <p className="text-sm"><IconActionButton as={Link} icon={Undo2} tone="brand" label={t('common.back')} to="/student" /></p>
    </section>
  )
}
