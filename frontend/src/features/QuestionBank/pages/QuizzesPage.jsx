import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import QuizHistory from '../components/QuizHistory'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// The student picks a subject, some lessons and how many questions, then starts a quiz made from their own grade.
export default function QuizzesPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const lang = i18n.language
  const [subjectId, setSubjectId] = useState('')
  const [picked, setPicked] = useState([])
  const [count, setCount] = useState(10)
  const [error, setError] = useState('')
  const options = useQuery({ queryKey: ['quizzes', 'options'], queryFn: async () => (await api.get('/quizzes/options')).data.data })
  const history = useQuery({ queryKey: ['quizzes', 'history'], queryFn: async () => (await api.get('/quizzes')).data.data })
  const subject = (options.data ?? []).find((s) => String(s.id) === String(subjectId))
  const available = (subject?.lessons ?? []).filter((l) => picked.includes(l.id)).reduce((n, l) => n + l.questions, 0)
  const start = useMutation({
    mutationFn: () => api.post('/quizzes', { subject_id: Number(subjectId), lesson_ids: picked, count: Number(count) }),
    onSuccess: (res) => navigate(`/student/quizzes/${res.data.data.id}`, { state: { reduced: res.data.reduced, available: res.data.available } }),
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const toggle = (id) => setPicked(picked.includes(id) ? picked.filter((x) => x !== id) : [...picked, id])
  return (
    <section className="space-y-5">
      <h1 className="text-xl font-bold">{t('quiz.title')}</h1>
      <form className="space-y-3 rounded-2xl border bg-white p-4" onSubmit={(e) => { e.preventDefault(); start.mutate() }}>
        <h2 className="font-bold">{t('quiz.newQuiz')}</h2>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {options.data && options.data.length === 0 ? <p className="text-slate-500">{t('quiz.noneYet')}</p> : (
          <>
            <label className="block text-sm sm:max-w-sm">{t('quiz.subject')}
              <select required className="mt-1 w-full rounded border p-2" value={subjectId} onChange={(e) => { setSubjectId(e.target.value); setPicked([]) }}>
                <option value="" disabled>—</option>
                {(options.data ?? []).map((s) => <option key={s.id} value={s.id}>{s[`name_${lang}`] || s.name_ar}</option>)}
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
            {picked.length > 0 && Number(count) > available && <p className="text-sm text-amber-700">{t('quiz.onlyAvailable', { n: available })}</p>}
            <button type="submit" disabled={start.isPending || picked.length === 0} className="rounded bg-brand px-6 py-2 text-white disabled:opacity-50">{t('quiz.generate')}</button>
          </>
        )}
      </form>
      <div className="space-y-2">
        <h2 className="font-bold">{t('quiz.history')}</h2>
        <QuizHistory rows={history.data} resumeBase="/student/quizzes" />
      </div>
      <p className="text-sm"><Link to="/student" className="text-brand underline">{t('common.back')}</Link></p>
    </section>
  )
}
