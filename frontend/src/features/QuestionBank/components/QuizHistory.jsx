import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

// A list of quizzes with the score; unfinished ones can be continued (when `resumeBase` is given).
export default function QuizHistory({ rows, resumeBase = null }) {
  const { t, i18n } = useTranslation()
  if (!rows) return <p className="text-slate-500">{t('common.loading')}</p>
  if (rows.length === 0) return <p className="rounded-lg border bg-white p-4 text-center text-slate-500">{t('quiz.noHistory')}</p>
  return (
    <ul className="divide-y rounded-lg border bg-white text-sm">
      {rows.map((r) => {
        const pct = r.total > 0 ? Math.round((r.correct / r.total) * 100) : 0
        return (
          <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
            <span><b dir="ltr">{r.date}</b> · {r[`subject_${i18n.language}`] || r.subject_ar}</span>
            <span className="flex items-center gap-3">
              <span>{t('quiz.scoreLine', { correct: r.correct, total: r.total })} {r.finished && <b className={pct >= 50 ? 'text-green-700' : 'text-red-700'} dir="ltr">{pct}%</b>}</span>
              {!r.finished && resumeBase && <Link to={`${resumeBase}/${r.id}`} className="text-brand underline">{t('quiz.continue')}</Link>}
              {!r.finished && !resumeBase && <span className="text-slate-500">{t('quiz.unfinished')}</span>}
            </span>
          </li>
        )
      })}
    </ul>
  )
}
