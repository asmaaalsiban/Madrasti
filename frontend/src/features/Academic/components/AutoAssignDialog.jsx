import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'

// Gives every subject of every classroom a teacher (from the curriculum), previewing first.
export default function AutoAssignDialog({ open, onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [maxLoad, setMaxLoad] = useState('24')
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const subjectName = (x) => (i18n.language === 'ar' ? x.subject_ar : x.subject)

  const run = useMutation({
    mutationFn: ({ dry }) => api.post('/teaching-assignments/auto', { max_load: Number(maxLoad), ...(dry ? { dry_run: true } : {}) }).then((r) => r.data.data),
    onSuccess: (data) => {
      setError('')
      setResult(data)
      if (!data.dry_run) client.invalidateQueries({ queryKey: ['teaching-assignments'] })
    },
    onError: (err) => { setResult(null); setError(err.response?.data?.message || t('admin.actions.error')) },
  })

  const close = () => { setResult(null); setError(''); onClose() }

  return (
    <Modal open={open} title={t('autoAssign.title')} onClose={close}>
      <div className="max-h-[75vh] space-y-3 overflow-y-auto">
        <p className="text-sm text-slate-600">{t('autoAssign.help')}</p>
        <label className="block text-sm">
          {t('autoAssign.maxLoad')}
          <input type="number" min="8" max="40" dir="ltr" className="mt-1 w-24 rounded border p-2" value={maxLoad} onChange={(e) => { setMaxLoad(e.target.value); setResult(null) }} />
        </label>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

        {result && (
          <div className="space-y-2">
            <p role="status" className="font-medium">
              {result.dry_run ? t('autoAssign.willAssign', { count: result.assigned, teachers: result.teachers.length }) : t('autoAssign.assigned', { count: result.assigned })}
            </p>
            {result.invalid > 0 && <p className="rounded bg-red-50 p-3 text-sm text-red-800">{t('autoAssign.invalid', { count: result.invalid })}</p>}
            {result.unassigned.length > 0 && (
              <div className="rounded bg-amber-50 p-3 text-sm text-amber-800">
                <p>{t('autoAssign.unassigned', { count: result.unassigned.length })}</p>
                <ul className="list-disc ps-5">
                  {result.shortages.map((s) => <li key={s.subject}>{t('autoAssign.shortage', { subject: subjectName(s), count: s.teachers_missing, periods: s.periods })}</li>)}
                </ul>
              </div>
            )}
            {result.teachers.length > 0 && (
              <table className="w-full text-start text-sm">
                <thead className="bg-slate-100">
                  <tr>{['teacher', 'subject', 'classrooms', 'load'].map((k) => <th key={k} className="p-1.5 text-start font-medium">{t(`autoAssign.${k}`)}</th>)}</tr>
                </thead>
                <tbody>
                  {result.teachers.map((x) => (
                    <tr key={x.id} className="border-t">
                      <td className="p-1.5">{x.name}</td><td className="p-1.5">{subjectName(x)}</td><td className="p-1.5">{x.classrooms}</td><td className="p-1.5">{x.load}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}

        <div className="flex justify-end gap-2 pt-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={close}>{t('autoAssign.close')}</button>
          <button type="button" disabled={run.isPending} className="rounded border px-3 py-1.5 disabled:opacity-50" onClick={() => run.mutate({ dry: true })}>{t('generate.preview')}</button>
          {result?.dry_run && result.assigned > 0 && (
            <button type="button" disabled={run.isPending} className="rounded bg-green-700 px-3 py-1.5 text-white disabled:opacity-50" onClick={() => run.mutate({ dry: false })}>{t('autoAssign.apply')}</button>
          )}
        </div>
      </div>
    </Modal>
  )
}
