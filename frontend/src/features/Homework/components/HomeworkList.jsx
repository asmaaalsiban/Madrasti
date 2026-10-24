import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import AnswerDialog from './AnswerDialog'
import HomeworkImage from './HomeworkImage'

// The homework of a section (student and parent), ordered by due date. canAnswer: the student can send an answer; a parent sees whether their child did.
export default function HomeworkList({ endpoint, canAnswer = false }) {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['homework', endpoint], queryFn: async () => (await api.get(endpoint)).data.data })
  const [answering, setAnswering] = useState(null)
  const subject = (s) => s[`name_${i18n.language}`] || s.name_ar

  if (isLoading) return <p>{t('common.loading')}</p>
  if (!data?.length) return <p className="rounded bg-white p-4 text-slate-600">{t('homework.none')}</p>
  return (
    <>
    <ul className="space-y-3">
      {data.map((h) => (
        <li key={h.id} className="space-y-1 rounded-lg border bg-white p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="font-bold">{h.title}</span>
            <span className="rounded-full bg-brand/10 px-2 py-0.5 text-xs text-brand">{subject(h.subject)}</span>
          </div>
          {h.description && <p className="whitespace-pre-line text-sm text-slate-700">{h.description}</p>}
          {h.has_image && <HomeworkImage id={h.id} />}
          <p className="text-xs text-slate-500">{t('homework.dueOn', { date: h.due_on })} · {h.teacher.name}</p>
          <div className="flex flex-wrap items-center gap-2 pt-1">
            {h.submission ? <span className="rounded bg-green-100 px-2 py-0.5 text-xs text-green-800">{t('homework.sentAt', { date: h.submission.submitted_at.slice(0, 10) })}{h.submission.late ? ` · ${t('homework.late')}` : ''}</span>
              : <span className="rounded bg-amber-50 px-2 py-0.5 text-xs text-amber-800">{t('homework.notSentYet')}</span>}
            {canAnswer && <button type="button" className="rounded bg-brand px-3 py-1 text-sm text-white" onClick={() => setAnswering(h)}>{h.submission ? t('homework.editAnswer') : t('homework.answer')}</button>}
          </div>
        </li>
      ))}
    </ul>
    {answering && <AnswerDialog key={answering.id} homework={answering} onClose={() => setAnswering(null)} />}
    </>
  )
}
