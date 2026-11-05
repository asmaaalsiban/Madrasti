import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ExportButton from '../../../components/shared/ExportButton'

// In the order a new school needs them: later kinds point to earlier ones (a section needs its grade).
const KINDS = ['grade_levels', 'subjects', 'staff', 'classrooms', 'teaching', 'students']
// For these a file may have one name only (Arabic or English), so "some name column" is what is needed.
const NAME_KINDS = ['students', 'subjects', 'grade_levels']
const WITH_ACCOUNTS = ['students', 'staff']
const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// Admin: add many students or staff from the school's own Excel/CSV file.
// 1. choose the file  2. check which column is which (guessed for you)  3. check every row  4. import.
export default function ImportPage() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [kind, setKind] = useState('students')
  const [file, setFile] = useState(null)
  const [analysis, setAnalysis] = useState(null) // headers, sample, suggested, fields
  const [mapping, setMapping] = useState({}) // column index -> field key ('' = ignore)
  const [defaultType, setDefaultType] = useState('teacher')
  const [report, setReport] = useState(null)
  const [error, setError] = useState('')

  const form = (confirm) => {
    const body = new FormData()
    body.append('file', file)
    body.append('confirm', confirm ? '1' : '0')
    body.append('mapping', JSON.stringify(Object.fromEntries(Object.entries(mapping).filter(([, v]) => v))))
    if (kind === 'staff' && !Object.values(mapping).includes('staff_type')) body.append('default_staff_type', defaultType)
    return body
  }

  const read = useMutation({
    mutationFn: async () => {
      const body = new FormData()
      body.append('file', file)
      return (await api.post(`/import/${kind}/analyze`, body)).data.data
    },
    onSuccess: (data) => { setError(''); setAnalysis(data); setMapping(Object.fromEntries(Object.entries(data.suggested).map(([i, k]) => [i, k ?? '']))) },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const send = useMutation({
    mutationFn: async (confirm) => ({ confirmed: confirm, ...(await api.post(`/import/${kind}`, form(confirm))).data.data }),
    onSuccess: (data) => { setError(''); setReport(data); if (data.confirmed) client.invalidateQueries() },
    onError: (err) => { setReport(null); setError(errorOf(err, t('admin.actions.error'))) },
  })

  const restart = (next = kind) => { setKind(next); setFile(null); setAnalysis(null); setReport(null); setError('') }
  const pickField = (index, key) => setMapping((m) => {
    const next = { ...m, [index]: key }
    if (key) for (const i of Object.keys(next)) if (i !== String(index) && next[i] === key) next[i] = '' // a field has one column
    return next
  })
  const done = report?.confirmed
  const mapped = Object.values(mapping).filter(Boolean)
  const missing = analysis ? analysis.fields.filter((f) => f.required && !mapped.includes(f.key)) : []
  const anyName = mapped.includes('name_ar') || mapped.includes('name_en') || mapped.includes('name')
  const ready = NAME_KINDS.includes(kind) ? anyName : missing.length === 0
  const createdColumns = report?.created_columns ?? []

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('import.title')}</h1>
      <p className="text-sm text-slate-600">{t('import.help')}</p>
      <p className="text-xs text-slate-500">{t('import.order')}</p>

      <div className="flex flex-wrap gap-2" role="tablist">
        {KINDS.map((k) => (
          <button key={k} type="button" role="tab" aria-selected={kind === k} className={`rounded border px-4 py-1.5 ${kind === k ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => restart(k)}>{t(`import.kinds.${k}`)}</button>
        ))}
      </div>

      <p className="text-sm"><Link to="/admin/student-files" className="text-brand underline">{t('import.studentFilesLink')}</Link></p>

      {error && <p role="alert" className="rounded bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      {!analysis && (
        <div className="space-y-3 rounded-lg border bg-white p-4">
          <p className="font-medium">{t('import.chooseFile')}</p>
          <p className="text-xs text-slate-500">{t(`import.note.${kind}`)}</p>
          <input type="file" aria-label={t('import.file')} accept=".xlsx,.csv" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setError('') }} />
          <div className="flex flex-wrap items-center gap-3">
            <button type="button" disabled={!file || read.isPending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50" onClick={() => read.mutate()}>{t('import.read')}</button>
            <ExportButton path={`/import/${kind}/template`} fallbackName={`${kind}.xlsx`} label={t('import.downloadTemplate')} />
          </div>
        </div>
      )}

      {analysis && !report && (
        <div className="space-y-3 rounded-lg border bg-white p-4">
          <p className="font-medium">{t('import.mapTitle', { count: analysis.row_count })}</p>
          <p className="text-xs text-slate-500">{t('import.mapHelp')}</p>
          <div className="overflow-x-auto">
            <table className="w-full text-start text-sm">
              <thead className="bg-slate-50"><tr><th className="p-2 text-start font-medium">{t('import.fileColumn')}</th><th className="p-2 text-start font-medium">{t('import.example')}</th><th className="p-2 text-start font-medium">{t('import.meaning')}</th></tr></thead>
              <tbody>
                {analysis.headers.map((header, i) => (
                  <tr key={i} className="border-t align-top">
                    <td className="p-2 font-medium">{header || `#${i + 1}`}</td>
                    <td className="p-2 text-slate-600">{analysis.sample.slice(0, 3).map((row) => row[i]).filter(Boolean).join(' · ')}</td>
                    <td className="p-2">
                      <select aria-label={header || `#${i + 1}`} className="rounded border p-1.5" value={mapping[i] ?? ''} onChange={(e) => pickField(i, e.target.value)}>
                        <option value="">{t('import.ignore')}</option>
                        {analysis.fields.map((f) => <option key={f.key} value={f.key}>{f.label}{f.required ? ' *' : ''}</option>)}
                      </select>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {kind === 'students' && !mapped.includes('student_number') && <p className="rounded bg-slate-50 p-2 text-xs text-slate-600">{t('import.autoNumbers')}</p>}
          {kind === 'staff' && !mapped.includes('email') && <p className="rounded bg-slate-50 p-2 text-xs text-slate-600">{t('import.autoEmails')}</p>}
          {kind === 'staff' && !mapped.includes('staff_type') && (
            <label className="block text-sm">{t('import.defaultPosition')}
              <select className="mt-1 block rounded border p-2" value={defaultType} onChange={(e) => setDefaultType(e.target.value)}>
                {analysis.positions.map((p) => <option key={p} value={p}>{p}</option>)}
              </select>
            </label>
          )}
          {!ready && NAME_KINDS.includes(kind) && <p role="alert" className="text-sm text-red-700">{t('import.needName')}</p>}
          {!ready && !NAME_KINDS.includes(kind) && <p role="alert" className="text-sm text-red-700">{t('import.needColumns', { columns: missing.map((f) => f.label).join(', ') })}</p>}

          <div className="flex gap-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={() => restart()}>{t('import.otherFile')}</button>
            <button type="button" disabled={send.isPending || !ready} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50" onClick={() => send.mutate(false)}>{t('import.check')}</button>
          </div>
        </div>
      )}

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

          <div className="flex gap-2">
            {!done && <button type="button" className="rounded border px-3 py-1.5" onClick={() => setReport(null)}>{t('import.back')}</button>}
            {!done && report.valid > 0 && (
              <button type="button" disabled={send.isPending} className="rounded bg-brand px-4 py-2 text-white disabled:opacity-50" onClick={() => send.mutate(true)}>{t('import.run', { count: report.valid })}</button>
            )}
            {done && <button type="button" className="rounded border px-3 py-1.5" onClick={() => restart()}>{t('import.another')}</button>}
          </div>

          {done && report.created.length > 0 && (
            <div className="space-y-2">
              {WITH_ACCOUNTS.includes(kind) && <p className="rounded bg-amber-50 p-3 text-sm text-amber-800">{t('import.passwordNote')}</p>}
              <div className="overflow-x-auto rounded border bg-white">
                <table className="w-full text-start text-sm">
                  <thead className="bg-slate-50"><tr>{createdColumns.map((c) => <th key={c} className="p-2 text-start font-medium">{t(`import.cols.${c}`, { defaultValue: c })}</th>)}</tr></thead>
                  <tbody>
                    {report.created.map((row, i) => (
                      <tr key={i} className="border-t">{createdColumns.map((c) => <td key={c} className="p-2" dir={c === 'email' || c === 'parent_login' ? 'ltr' : undefined}>{row[c]}</td>)}</tr>
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
