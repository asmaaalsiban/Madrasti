import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'

// Edits one cell of a section's week by hand: pick a subject (its teacher comes from the teacher assignment), lock, or empty the cell.
export default function EditLessonDialog({ classroomId, period, entry, onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [subjectId, setSubjectId] = useState(entry ? String(entry.subject.id) : '')
  const [joint, setJoint] = useState(Boolean(entry?.is_joint))
  const [error, setError] = useState('')
  const name = (s) => s[`name_${i18n.language}`] || s.name_ar

  const assignments = useQuery({
    queryKey: ['timetable', 'assignments', classroomId],
    queryFn: async () => (await api.get('/teaching-assignments', { params: { classroom_id: classroomId, per_page: 100 } })).data.data,
  })
  const done = () => { client.invalidateQueries({ queryKey: ['timetable'] }); onClose() }
  const fail = (err) => setError(err.response?.data?.errors?.period_id?.[0] || err.response?.data?.errors?.subject_id?.[0] || err.response?.data?.message || t('admin.actions.error'))

  const save = useMutation({ mutationFn: () => api.put('/timetable/entries', { classroom_id: classroomId, period_id: period.id, subject_id: Number(subjectId), ...(joint ? { joint: true } : {}) }), onSuccess: done, onError: fail })
  const lock = useMutation({ mutationFn: () => api.put(`/timetable/entries/${entry.id}/lock`, { locked: !entry.is_locked }), onSuccess: done, onError: fail })
  const remove = useMutation({ mutationFn: () => api.delete(`/timetable/entries/${entry.id}`), onSuccess: done, onError: fail })

  const chosen = (assignments.data ?? []).find((a) => String(a.subject_id) === subjectId)

  return (
    <Modal open title={t('timetable.editTitle', { day: t(`days.${period.day_of_week}`), number: period.number })} onClose={onClose}>
      <div className="space-y-3">
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">
          {t('academic.fields.subject')}
          <select className="mt-1 block w-full rounded border p-2" value={subjectId} onChange={(e) => setSubjectId(e.target.value)}>
            <option value="">—</option>
            {(assignments.data ?? []).map((a) => <option key={a.id} value={a.subject_id}>{name(a.subject)}</option>)}
          </select>
        </label>
        {chosen && <p className="text-sm text-slate-600">{t('timetable.teacherIs', { name: chosen.teacher?.name })}</p>}
        <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={joint} onChange={(e) => setJoint(e.target.checked)} /><span>{t('timetable.joint')}<span className="block text-xs text-slate-500">{t('timetable.jointHelp')}</span></span></label>
        <p className="text-xs text-slate-500">{t('timetable.editHelp')}</p>
        <div className="flex flex-wrap justify-between gap-2">
          <div className="flex gap-2">
            {entry && <button type="button" className="rounded border px-3 py-1.5 text-sm" onClick={() => lock.mutate()}>{entry.is_locked ? t('timetable.unlock') : t('timetable.lock')}</button>}
            {entry && <button type="button" className="rounded border border-red-300 px-3 py-1.5 text-sm text-red-700" onClick={() => remove.mutate()}>{t('timetable.emptyCell')}</button>}
          </div>
          <div className="flex gap-2">
            <button type="button" className="rounded border px-3 py-1.5 text-sm" onClick={onClose}>{t('admin.actions.cancel')}</button>
            <button type="button" className="rounded bg-brand px-4 py-1.5 text-sm text-white disabled:opacity-50" disabled={!subjectId || save.isPending} onClick={() => save.mutate()}>{t('admin.actions.save')}</button>
          </div>
        </div>
      </div>
    </Modal>
  )
}
