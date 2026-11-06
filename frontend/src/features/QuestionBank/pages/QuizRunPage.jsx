import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// One question at a time. A right answer moves on at once; a wrong one shows the right answer and how to solve it.
export default function QuizRunPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const client = useQueryClient()
  const location = useLocation()
  const [feedback, setFeedback] = useState(null) // { quizQuestionId, optionId, correct, correct_option_id, explanation }
  const [error, setError] = useState('')
  const { data: quiz } = useQuery({ queryKey: ['quizzes', 'run', id], queryFn: async () => (await api.get(`/quizzes/${id}`)).data.data })
  const answer = useMutation({
    mutationFn: ({ q, optionId }) => api.post(`/quizzes/${id}/answer`, { quiz_question_id: q.id, option_id: optionId }).then((r) => ({ ...r.data, q, optionId })),
    onSuccess: (r) => {
      client.invalidateQueries({ queryKey: ['quizzes', 'history'] })
      client.setQueryData(['quizzes', 'run', id], (old) => old && ({ ...old, finished: r.finished, correct: r.score?.correct ?? old.correct, questions: old.questions.map((x) => (x.id === r.q.id ? { ...x, answered: true, is_correct: r.correct } : x)) }))
      if (r.correct) setFeedback(null)
      else setFeedback({ quizQuestionId: r.q.id, optionId: r.optionId, correct_option_id: r.correct_option_id, explanation: r.explanation })
    },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')),
  })
  if (!quiz) return <p className="text-slate-500">{t('common.loading')}</p>
  const total = quiz.questions.length
  const next = () => setFeedback(null)
  const showing = feedback ? quiz.questions.find((q) => q.id === feedback.quizQuestionId) : quiz.questions.find((q) => !q.answered)
  if (!showing) {
    const pct = total > 0 ? Math.round((quiz.correct / total) * 100) : 0
    return (
      <section className="mx-auto max-w-xl space-y-4 text-center">
        <h1 className="text-xl font-bold">{t('quiz.finished')}</h1>
        <p className="text-5xl font-extrabold" dir="ltr">{quiz.correct} / {total}</p>
        <p className={`text-lg font-bold ${pct >= 50 ? 'text-green-700' : 'text-red-700'}`} dir="ltr">{pct}%</p>
        <Link to="/student/quizzes" className="inline-block rounded bg-brand px-6 py-2 text-white">{t('quiz.backToQuizzes')}</Link>
      </section>
    )
  }
  const wrongNow = feedback !== null
  return (
    <section className="mx-auto max-w-2xl space-y-4">
      {location.state?.reduced && <p className="rounded bg-amber-50 p-2 text-sm text-amber-800">{t('quiz.reduced', { n: location.state.available })}</p>}
      <p className="text-sm text-slate-500">{t('quiz.questionOf', { n: showing.position, total })}</p>
      <h1 dir="auto" className="text-lg font-bold">{showing.body}</h1>
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      <ul className="space-y-2">
        {showing.options.map((o) => {
          const isRight = wrongNow && o.id === feedback.correct_option_id
          const isChosenWrong = wrongNow && o.id === feedback.optionId
          return (
            <li key={o.id}>
              <button type="button" dir="auto" disabled={wrongNow || answer.isPending} onClick={() => { setError(''); answer.mutate({ q: showing, optionId: o.id }) }}
                className={`w-full rounded-xl border p-3 text-start ${isRight ? 'border-green-600 bg-green-50 font-bold' : isChosenWrong ? 'border-red-500 bg-red-50' : 'bg-white hover:bg-sky-tint'}`}>
                {o.body}{isRight && <span className="ms-2 text-green-700">✓</span>}
              </button>
            </li>
          )
        })}
      </ul>
      {wrongNow && (
        <div className="space-y-3 rounded-xl bg-slate-50 p-4">
          <p className="font-bold text-red-700">{t('quiz.wrong')}</p>
          <div><p className="text-sm font-bold">{t('quiz.howToSolve')}</p><p dir="auto" className="whitespace-pre-line text-sm">{feedback.explanation || t('quiz.noExplanation')}</p></div>
          <button type="button" className="rounded bg-brand px-6 py-2 text-white" onClick={next}>{t('quiz.next')}</button>
        </div>
      )}
    </section>
  )
}
