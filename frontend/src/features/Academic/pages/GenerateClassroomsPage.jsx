import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useResource } from '../hooks/useResource'

const FIELDS = [['mixed', 'mixed'], ['male', 'boys'], ['female', 'girls']]

// Creates the sections of every grade at once: how many mixed, boys and girls sections each grade has.
// The server places them in free classrooms of the floors that serve the grade (preview first).
export default function GenerateClassroomsPage() {
  const { t, i18n } = useTranslation()
  const [counts, setCounts] = useState({}) // gradeId -> { mixed, male, female }
  const [year, setYear] = useState('')
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')

  const grades = useResource('grade-levels').useList({ per_page: 100 })
  const years = useQuery({ queryKey: ['academic-years', 'all'], queryFn: async () => (await api.get('/academic-years', { params: { per_page: 100 } })).data.data })
  const gradeList = [...(grades.data?.data ?? [])].sort((a, b) => a.sort_order - b.sort_order)
  const yearId = year || years.data?.find((y) => y.is_current)?.id || years.data?.[0]?.id || ''
  const gradeName = (g) => g[`name_${i18n.language}`] ?? g.name_ar

  const plan = gradeList
    .map((g) => ({ grade_level_id: g.id, male: Number(counts[g.id]?.male || 0), female: Number(counts[g.id]?.female || 0), mixed: Number(counts[g.id]?.mixed || 0) }))
    .filter((p) => p.male + p.female + p.mixed > 0)
  const total = plan.reduce((sum, p) => sum + p.male + p.female + p.mixed, 0)

  const run = useMutation({
    mutationFn: ({ dry }) => api.post('/classrooms/generate', { academic_year_id: Number(yearId), ...(dry ? { dry_run: true } : {}), plan }).then((r) => r.data.data),
    onSuccess: (data) => { setError(''); setResult(data) },
    onError: (err) => { setResult(null); setError(err.response?.data?.message || t('admin.actions.error')) },
  })

  const set = (gradeId, field) => (e) => { setResult(null); setCounts({ ...counts, [gradeId]: { ...counts[gradeId], [field]: e.target.value } }) }
  const gradeOf = (id) => gradeList.find((g) => g.id === id)

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('generate.title')}</h1>
        <Link to="/admin/classrooms" className="text-sm text-brand underline">{t('generate.toClassrooms')}</Link>
      </div>
      <p className="text-sm text-slate-600">{t('generate.help')}</p>

      <label className="block text-sm">
        {t('academic.fields.academicYear')}
        <select className="mt-1 block rounded border p-2" value={yearId} onChange={(e) => { setYear(e.target.value); setResult(null) }}>
          {(years.data ?? []).map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
        </select>
      </label>

      <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-100">
            <tr>
              <th className="p-2 text-start font-medium sm:p-3">{t('academic.fields.gradeLevel')}</th>
              {FIELDS.map(([, label]) => <th key={label} className="p-2 text-start font-medium sm:p-3">{t(`generate.${label}`)}</th>)}
            </tr>
          </thead>
          <tbody>
            {gradeList.map((g) => (
              <tr key={g.id} className="border-t">
                <td className="p-2 font-medium sm:p-3">{gradeName(g)}</td>
                {FIELDS.map(([field, label]) => (
                  <td key={field} className="p-2 sm:p-3">
                    <input
                      type="number" min="0" max="26" dir="ltr" aria-label={t(`generate.${label}`)} className="w-20 rounded border p-1.5"
                      value={counts[g.id]?.[field] ?? ''} onChange={set(g.id, field)}
                    />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="font-medium">{t('generate.total', { count: total })}</p>
        <button type="button" disabled={total === 0 || run.isPending} className="rounded bg-brand px-5 py-2 text-white disabled:opacity-60" onClick={() => run.mutate({ dry: true })}>
          {t('generate.preview')}
        </button>
      </div>

      {error && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{error}</p>}

      {result && (
        <div className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
          <p role="status" className="font-medium">
            {result.dry_run ? t('generate.willCreate', { created: result.created, placed: result.placed }) : t('generate.created', { count: result.created })}
          </p>
          {result.created === 0 && (
            <div className="rounded bg-blue-50 p-3 text-sm text-blue-900">
              <p>{t('generate.nothingNew')}</p>
              <ul className="list-disc ps-5">
                {result.existing.map((e) => <li key={e.grade_level_id}>{t('generate.existingRow', { grade: gradeName(gradeOf(e.grade_level_id) ?? {}), mixed: e.mixed, boys: e.male, girls: e.female })}</li>)}
              </ul>
            </div>
          )}
          {result.unplaced.length > 0 && <p className="rounded bg-amber-50 p-3 text-sm text-amber-800">{t('generate.unplaced', { names: result.unplaced.join(', ') })}</p>}
          <div className="max-h-96 overflow-y-auto">
            <table className="w-full text-start text-sm">
              <tbody>
                {result.classrooms.map((c) => (
                  <tr key={c.name} className="border-t">
                    <td className="p-2 font-medium">{c.name}</td>
                    <td className="p-2">{gradeName(gradeOf(c.grade_level_id) ?? {})}</td>
                    <td className="p-2">{t(`buildings.gender.${c.gender}`)}</td>
                    <td className="p-2">{c.room ? `${c.room.building} · ${c.room.floor} · ${c.room.name}` : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {result.dry_run && result.created > 0 && (
            <button type="button" disabled={run.isPending} className="rounded bg-green-700 px-5 py-2 text-white disabled:opacity-60" onClick={() => run.mutate({ dry: false })}>
              {t('generate.create')}
            </button>
          )}
        </div>
      )}
    </section>
  )
}
