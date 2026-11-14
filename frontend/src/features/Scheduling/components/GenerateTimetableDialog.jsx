import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// Builds the timetable of every section from the teacher assignments: preview first (nothing is saved), then apply.
export default function GenerateTimetableDialog({ open, onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [report, setReport] = useState(null)
  const [error, setError] = useState('')
  const subject = (x) => (i18n.language === 'ar' ? x.subject_ar : x.subject)

  const run = useMutation({
    mutationFn: ({ apply }) => api.post('/timetable/generate', apply ? { seed: report.seed } : { dry_run: true }).then((r) => r.data.data),
    onSuccess: (data) => {
      setError('')
      setReport(data)
      if (!data.dry_run) client.invalidateQueries({ queryKey: ['timetable'] })
    },
    onError: (err) => { setReport(null); setError(err.response?.data?.message || Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')) },
  })
  const close = () => { setReport(null); setError(''); onClose() }

  return (
    <Modal open={open} title={t('timetable.generateTitle')} onClose={close}>
      <div className="max-h-[75vh] space-y-3 overflow-y-auto">
        <p className="text-sm text-slate-600">{t('timetable.generateHelp')}</p>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {report && (
          <div className="space-y-2">
            <p role="status" className="font-medium">
              {report.dry_run ? t('timetable.canPlace', { placed: report.placed + report.locked, total: report.total }) : t('timetable.saved', { count: report.placed })}
            </p>
            {report.unplaced.length > 0 && (
              <div className="rounded bg-amber-50 p-3 text-sm text-amber-800">
                <p>{t('timetable.unplaced')}</p>
                <ul className="list-disc ps-5">{report.unplaced.map((u) => <li key={`${u.classroom}-${u.subject}`}>{`${u.classroom} · ${subject(u)}: ${u.count}`}</li>)}</ul>
              </div>
            )}
            {report.out_of_scope?.length > 0 && (
              <div className="rounded bg-red-50 p-3 text-sm text-red-800">
                <p>{t('timetable.outOfScope')}</p>
                <ul className="list-disc ps-5">{report.out_of_scope.map((u) => <li key={`${u.classroom}-${u.subject}`}>{`${u.classroom} · ${subject(u)} · ${u.teacher}`}</li>)}</ul>
              </div>
            )}
            {report.incomplete?.length > 0 && (
              <div className="rounded bg-amber-50 p-3 text-sm text-amber-800">
                <p>{t('timetable.incomplete')}</p>
                <ul className="list-disc ps-5">{report.incomplete.map((c) => <li key={c.classroom}>{t('timetable.incompleteRow', c)}</li>)}</ul>
              </div>
            )}
            {report.without_teacher.length > 0 && (
              <div className="rounded bg-amber-50 p-3 text-sm text-amber-800">
                <p>{t('timetable.withoutTeacher')}</p>
                <ul className="list-disc ps-5">{report.without_teacher.map((u) => <li key={`${u.classroom}-${u.subject}`}>{`${u.classroom} · ${subject(u)}: ${t('timetable.noTeacher')}`}</li>)}</ul>
              </div>
            )}
          </div>
        )}
        <div className="flex justify-end gap-2 pt-2">
          <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5" onClick={close}>{t('autoAssign.close')}</InteractiveHoverButton>
          <InteractiveHoverButton variant="outline" type="button" disabled={run.isPending} className="px-3 py-1.5 disabled:opacity-50" onClick={() => run.mutate({ apply: false })}>{t('generate.preview')}</InteractiveHoverButton>
          {report?.dry_run && report.placed > 0 && (
            <button type="button" disabled={run.isPending} className="rounded bg-green-700 px-3 py-1.5 text-white disabled:opacity-50" onClick={() => run.mutate({ apply: true })}>{t('timetable.apply')}</button>
          )}
        </div>
      </div>
    </Modal>
  )
}
