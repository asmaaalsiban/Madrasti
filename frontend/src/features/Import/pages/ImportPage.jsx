import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ExportButton from '../../../components/shared/ExportButton'

const KINDS = ['students', 'staff']
const CREATED_COLUMNS = { students: ['student_number', 'name', 'email'], staff: ['name', 'email', 'staff_type'] }

// Admin: add many students or staff from an Excel/CSV file. Step 1 checks the file and creates nothing; step 2 imports the valid rows.
export default function ImportPage() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [kind, setKind] = useState('students')
  const [file, setFile] = useState(null)
  const [report, setReport] = useState(null) // { confirmed, ...data }
  const [error, setError] = useState('')

  const send = useMutation({
    mutationFn: async (confirm) => {
      const body = new FormData()
      body.append('file', file)
      body.append('confirm', confirm ? '1' : '0')
      return { confirmed: confirm, ...(await api.post(`/import/${kind}`, body)).data.data }
    },
    onSuccess: (data) => { setError(''); setReport(data); if (data.confirmed) client.invalidateQueries() },
    onError: (err) => { setReport(null); setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || t('admin.actions.error')) },
  })

  const reset = (next) => { setKind(next); setFile(null); setReport(null); setError('') }
  const done = report?.confirmed

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('import.title')}</h1>
      <p className="text-sm text-slate-600">{t('import.help')}</p>

      <div className="flex gap-2" role="tablist">
        {KINDS.map((k) => (
          <button key={k} type="button" role="tab" aria-selected={kind === k} className={`rounded border px-4 py-1.5 ${kind === k ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => reset(k)}>{t(`import.kinds.${k}`)}</button>
        ))}
      </div>

      <ol className="space-y-3 rounded-lg border bg-white p-4">
        <li className="space-y-1">
          <p className="font-medium">1. {t('import.step1')}</p>
          <ExportButton path={`/import/${kind}/template`} fallbackName={`${kind}.xlsx`} label={t('import.downloadTemplate')} />
          <p className="text-xs text-slate-500">{t(`import.note.${kind}`)}</p>
        </li>
        <li className="space-y-2">
          <p className="font-medium">2. {t('import.step2')}</p>
          <input type="file" aria-label={t('import.file')} accept=".xlsx,.csv" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setReport(null); setError('') }} />
          <div>
            <button type="button" disabled={!file || send.isPending} className="rounded border px-4 py-1.5 text-brand disabled:opacity-50" onClick={() => send.mutate(false)}>{t('import.check')}</button>
          </div>
        </li>
      </ol>

      {error && <p role="alert" className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      {report && (
        <div className="space-y-3">
          <p role="status" className="rounded bg-slate-50 p-3 text-sm">
            {done ? t('import.done', { created: report.created.length, skipped: report.problem_count }) : t('import.checked', { total: report.total, valid: report.valid, problems: report.problem_count })}
          </p>

          {report.problem_count > 0 && (
            <div>
              <h2 className="mb-1 font-bold">{done ? t('import.skipped') : t('import.problems')}</h2>
              <ul className="max-h-64 space-y-1 overflow-y-auto text-sm">
                {report.problems.map((p) => (
                  <li key={p.line} className="rounded border border-red-200 bg-red-50 p-2"><b>{t('import.line', { line: p.line })}</b> {p.errors.join(' · ')}</li>
                ))}
              </ul>
            </div>
          )}

          {!done && report.valid > 0 && (
            <button type="button" disabled={send.isPending} className="rounded bg-brand px-4 py-2 text-white disabled:opacity-50" onClick={() => send.mutate(true)}>{t('import.run', { count: report.valid })}</button>
          )}

          {done && report.created.length > 0 && (
            <div className="space-y-2">
              <p className="rounded bg-amber-50 p-3 text-sm text-amber-800">{t('import.passwordNote')}</p>
              <div className="overflow-x-auto rounded border bg-white">
                <table className="w-full text-start text-sm">
                  <thead className="bg-slate-50"><tr>{CREATED_COLUMNS[kind].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`import.cols.${c}`)}</th>)}</tr></thead>
                  <tbody>
                    {report.created.map((row, i) => (
                      <tr key={i} className="border-t">{CREATED_COLUMNS[kind].map((c) => <td key={c} className="p-2" dir={c === 'email' ? 'ltr' : undefined}>{row[c]}</td>)}</tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>
      )}
    </section>
  )
}
