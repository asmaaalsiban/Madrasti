import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { today } from '../../../lib/dates'
import ResultBadge from './ResultBadge'

const KIND = { pass: 'pass', fail: 'fail', makeup: 'pending', retake: 'pending', none: 'none' }
const STATUS_LABEL = { absent: 'studentGrades.statusAbsent', makeup: 'studentGrades.statusMakeup', retake: 'studentGrades.statusRetake' }

// The published grades of one student by term: a card per subject with its result and, on demand, its assessments.
export default function StudentGrades({ studentId }) {
  const { t, i18n } = useTranslation()
  const [termId, setTermId] = useState(null)
  const [open, setOpen] = useState({})
  const { data, isLoading, isError } = useQuery({
    queryKey: ['student-grades', studentId],
    queryFn: async () => (await api.get(`/students/${studentId}/grades`)).data.data,
  })

  if (isLoading) return <p className="text-slate-500">{t('common.loading')}</p>
  if (isError || !data) return <p role="alert" className="text-red-700">{t('admin.actions.error')}</p>

  // Default term: the one running today, otherwise the last one that has grades.
  const now = today()
  const fallback = data.terms.find((x) => x.starts_on <= now && now <= x.ends_on) ?? [...data.terms].reverse().find((x) => x.subjects.length > 0) ?? data.terms[0]
  const term = data.terms.find((x) => x.id === termId) ?? fallback
  const subjectName = (s) => s[`name_${i18n.language}`] || s.name_ar

  return (
    <div className="space-y-4">
      <div role="tablist" className="flex flex-wrap gap-2">
        {data.terms.map((x) => (
          <button
            key={x.id} type="button" role="tab" aria-selected={x.id === term?.id}
            className={`rounded-full border px-3 py-1 text-sm ${x.id === term?.id ? 'bg-brand text-white' : 'bg-white'}`}
            onClick={() => setTermId(x.id)}
          >
            {x.name}
          </button>
        ))}
      </div>

      {term && term.subjects.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('studentGrades.empty')}</p>}

      <div className="grid gap-3 sm:grid-cols-2">
        {term?.subjects.map((s) => {
          const key = `${term.id}-${s.subject.id}`
          return (
            <div key={key} role="group" aria-label={subjectName(s.subject)} className="space-y-2 rounded-lg border bg-white p-4">
              <div className="flex items-center justify-between gap-2">
                <p className="font-bold">{subjectName(s.subject)}</p>
                {s.percent !== null && <span dir="ltr" className="text-sm text-slate-600">{s.percent}%</span>}
              </div>
              <ResultBadge kind={KIND[s.result]} label={t(`studentGrades.result.${s.result}`)} />
              <div>
                <button type="button" className="text-sm text-brand underline" aria-expanded={Boolean(open[key])} onClick={() => setOpen({ ...open, [key]: !open[key] })}>
                  {t(open[key] ? 'studentGrades.hideDetails' : 'studentGrades.showDetails')}
                </button>
              </div>
              {open[key] && (
                <ul className="divide-y text-sm">
                  {s.assessments.map((a) => (
                    <li key={a.id} className="space-y-0.5 py-2">
                      <div className="flex justify-between gap-2">
                        <span>{a.title}</span>
                        <span dir="ltr">{a.score === null ? '—' : `${a.score} / ${a.max_score}`}</span>
                      </div>
                      <div className="flex flex-wrap gap-x-3 text-xs text-slate-500">
                        <span dir="ltr">{a.held_on}</span>
                        {a.status && <span>{t(STATUS_LABEL[a.status])}</span>}
                        {a.first_score !== null && <span>{t('studentGrades.firstAttempt', { score: a.first_score })}</span>}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}
