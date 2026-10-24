import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { HomeworkPhotos } from './HomeworkImage'

// The teacher's list for one homework: who sent an answer (with its text and photo) and who has not.
export default function SubmissionsDialog({ homework, onClose }) {
  const { t, i18n } = useTranslation()
  const [open, setOpen] = useState(null) // student id whose answer is shown
  const { data, isLoading } = useQuery({ queryKey: ['homework', 'submissions', homework.id], queryFn: async () => (await api.get(`/homework/${homework.id}/submissions`)).data.data })
  const name = (s) => s[`name_${i18n.language}`] || s.name_ar
  const answered = (data ?? []).filter((r) => r.submission).length

  return (
    <Modal open size="lg" title={t('homework.answersTitle', { title: homework.title })} onClose={onClose}>
      <div className="space-y-3">
        {isLoading ? <p>{t('common.loading')}</p> : (
          <>
            <p className="text-sm text-slate-600">{t('homework.answeredCount', { done: answered, total: data.length })}</p>
            <ul className="space-y-2">
              {data.map(({ student, submission }) => (
                <li key={student.id} className="rounded border bg-white p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span>{name(student)}</span>
                    {submission ? (
                      <button type="button" className="rounded bg-green-100 px-3 py-1 text-sm text-green-800" onClick={() => setOpen(open === student.id ? null : student.id)}>
                        {t('homework.sentAt', { date: submission.submitted_at.slice(0, 10) })}{submission.late ? ` · ${t('homework.late')}` : ''}
                      </button>
                    ) : <span className="rounded bg-red-50 px-3 py-1 text-sm text-red-700">{t('homework.notSent')}</span>}
                  </div>
                  {submission && open === student.id && (
                    <div className="mt-2 space-y-2">
                      {submission.body && <p className="whitespace-pre-line text-sm">{submission.body}</p>}
                      <HomeworkPhotos ids={submission.images} />
                    </div>
                  )}
                </li>
              ))}
            </ul>
          </>
        )}
        <div className="flex justify-end"><button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('homework.close')}</button></div>
      </div>
    </Modal>
  )
}
