import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Timer } from 'lucide-react'
import { api } from '../../../api/client'
import RichText from '../components/RichText'
import QuestionImage from '../components/QuestionImage'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const clock = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

// A countdown that follows the server's time left. At zero the quiz is closed by the server and we reload it.
function Countdown({ startsAt, onEnd }) {
  const { t } = useTranslation()
  const [left, setLeft] = useState(startsAt)
  useEffect(() => {
    const end = Date.now() + startsAt * 1000
    const id = setInterval(() => {
      const next = Math.max(0, Math.ceil((end - Date.now()) / 1000))
      setLeft(next)
      if (next === 0) { clearInterval(id); onEnd() }
    }, 500)
    return () => clearInterval(id)
  }, [startsAt, onEnd])
  return <p className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-bold ${left <= 30 ? 'bg-red-100 text-red-700' : 'bg-slate-100'}`} role="timer" aria-label={t('quiz.timeLeft')}><Timer size={14} aria-hidden /><span dir="ltr">{clock(left)}</span></p>
}

// One question at a time. A right answer moves on at once; a wrong one shows the right answer and how to solve it.
export default function QuizRunPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const navigate = useNavigate()
  const client = useQueryClient()
  const location = useLocation()
  const [feedback, setFeedback] = useState(null) // { quizQuestionId, optionId, correct_option_id, explanation, has_explanation_image }
  const [error, setError] = useState('')
  const { data: quiz } = useQuery({ queryKey: ['quizzes', 'run', id], queryFn: async () => (await api.get(`/quizzes/${id}`)).data.data })
  const reload = () => client.invalidateQueries({ queryKey: ['quizzes'] })
  const answer = useMutation({
    mutationFn: ({ q, optionId }) => api.post(`/quizzes/${id}/answer`, { quiz_question_id: q.id, option_id: optionId }).then((r) => ({ ...r.data, q, optionId })),
    onSuccess: (r) => {
      client.invalidateQueries({ queryKey: ['quizzes', 'history'] })
      client.setQueryData(['quizzes', 'run', id], (old) => old && ({ ...old, finished: r.finished, correct: r.score?.correct ?? old.correct, wrong: r.score?.wrong ?? old.wrong, questions: old.questions.map((x) => (x.id === r.q.id ? { ...x, answered: true, is_correct: r.correct } : x)) }))
      if (r.correct) setFeedback(null)
      else setFeedback({ quizQuestionId: r.q.id, optionId: r.optionId, correct_option_id: r.correct_option_id, explanation: r.explanation, has_explanation_image: r.has_explanation_image })
    },
    onError: (err) => { setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')); reload() },
  })
  const retake = useMutation({ mutationFn: () => api.post(`/quizzes/${id}/retake`), onSuccess: (res) => { reload(); navigate(`/student/quizzes/${res.data.data.id}`) } })
  const practice = useMutation({ mutationFn: () => api.post('/quizzes', { mode: 'mistakes' }), onSuccess: (res) => { reload(); navigate(`/student/quizzes/${res.data.data.id}`) } })
  if (!quiz) return <p className="text-slate-500">{t('common.loading')}</p>
  const total = quiz.questions.length
  const showing = feedback ? quiz.questions.find((q) => q.id === feedback.quizQuestionId) : (quiz.finished ? undefined : quiz.questions.find((q) => !q.answered))
  if (!showing) {
    const pct = total > 0 ? Math.round((quiz.correct / total) * 100) : 0
    return (
      <section className="mx-auto max-w-xl space-y-4 text-center">
        <h1 className="text-xl font-bold">{quiz.timed_out ? t('quiz.timeUp') : t('quiz.finished')}</h1>
        <p className="text-5xl font-extrabold" dir="ltr">{quiz.correct} / {total}</p>
        <p className={`text-lg font-bold ${pct >= 50 ? 'text-green-700' : 'text-red-700'}`} dir="ltr">{pct}%</p>
        <div className="flex flex-wrap justify-center gap-2">
          <button type="button" disabled={retake.isPending} className="rounded border border-brand px-5 py-2 text-brand" onClick={() => retake.mutate()}>{t('quiz.retake')}</button>
          {(quiz.wrong > 0 || quiz.answered < total) && <button type="button" disabled={practice.isPending} className="rounded bg-amber-600 px-5 py-2 text-white" onClick={() => practice.mutate()}>{t('quiz.practiceMistakes')}</button>}
          <Link to="/student/quizzes" className="rounded bg-brand px-5 py-2 text-white">{t('quiz.backToQuizzes')}</Link>
        </div>
      </section>
    )
  }
  const wrongNow = feedback !== null
  const base = `/quizzes/${id}/items/${showing.id}/image`
  return (
    <section className="mx-auto max-w-2xl space-y-4">
      {location.state?.reduced && <p className="rounded bg-amber-50 p-2 text-sm text-amber-800">{t('quiz.reduced', { n: location.state.available })}</p>}
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm text-slate-500">{t('quiz.questionOf', { n: showing.position, total })}</p>
        {quiz.seconds_left !== null && quiz.seconds_left !== undefined && <Countdown startsAt={quiz.seconds_left} onEnd={reload} />}
      </div>
      <h1 className="text-lg font-bold"><RichText text={showing.body} /></h1>
      {showing.has_image && <QuestionImage path={`${base}/question`} alt={t('questionBank.imageAlt')} />}
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      <ul className="space-y-2">
        {showing.options.map((o) => {
          const isRight = wrongNow && o.id === feedback.correct_option_id
          const isChosenWrong = wrongNow && o.id === feedback.optionId
          return (
            <li key={o.id}>
              <button type="button" dir="auto" disabled={wrongNow || answer.isPending} onClick={() => { setError(''); answer.mutate({ q: showing, optionId: o.id }) }}
                className={`w-full rounded-xl border p-3 text-start ${isRight ? 'border-green-600 bg-green-50 font-bold' : isChosenWrong ? 'border-red-500 bg-red-50' : 'bg-white hover:bg-sky-tint'}`}>
                <RichText text={o.body} />{isRight && <span className="ms-2 text-green-700">✓</span>}
              </button>
            </li>
          )
        })}
      </ul>
      {wrongNow && (
        <div className="space-y-3 rounded-xl bg-slate-50 p-4">
          <p className="font-bold text-red-700">{t('quiz.wrong')}</p>
          <div>
            <p className="text-sm font-bold">{t('quiz.howToSolve')}</p>
            <p className="text-sm">{feedback.explanation ? <RichText text={feedback.explanation} /> : t('quiz.noExplanation')}</p>
            {feedback.has_explanation_image && <div className="mt-2"><QuestionImage path={`${base}/explanation`} alt={t('questionBank.imageAlt')} /></div>}
          </div>
          <InteractiveHoverButton type="button" className="px-6 py-2" onClick={() => setFeedback(null)}>{t('quiz.next')}</InteractiveHoverButton>
        </div>
      )}
    </section>
  )
}
