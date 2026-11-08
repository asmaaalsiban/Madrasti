import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import Modal from '../../../components/shared/Modal'
import ClassTeacherGrades from '../components/ClassTeacherGrades'

const key = (grade, subject) => `${grade}-${subject}`

// The curriculum: subjects as rows, grades as columns, periods per week in the cells.
export default function CurriculumPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [values, setValues] = useState({})
  const [dirty, setDirty] = useState(false)
  const [message, setMessage] = useState({ kind: '', text: '', extra: '' })
  const [confirmPlan, setConfirmPlan] = useState(false)
  const [copy, setCopy] = useState(null) // { from, to: [] } | null

  const { data } = useQuery({ queryKey: ['curriculum'], queryFn: async () => (await api.get('/curriculum')).data.data })

  useEffect(() => {
    if (!data || dirty) return
    setValues(Object.fromEntries(data.cells.map((c) => [key(c.grade_level_id, c.subject_id), String(c.weekly_periods)])))
  }, [data, dirty])

  const refresh = () => client.invalidateQueries({ queryKey: ['curriculum'] })
  const fail = (err) => setMessage({ kind: 'error', text: err.response?.data?.message || t('admin.actions.error'), extra: '' })

  const save = useMutation({
    mutationFn: () => {
      const cells = []
      for (const g of data.grades) {
        for (const s of data.subjects) {
          const n = Number(values[key(g.id, s.id)] || 0)
          if (n > 0) cells.push({ grade_level_id: g.id, subject_id: s.id, weekly_periods: n })
        }
      }
      return api.put('/curriculum', { cells })
    },
    onSuccess: () => { setDirty(false); setMessage({ kind: 'ok', text: t('curriculum.saved'), extra: '' }); refresh() },
    onError: fail,
  })

  const applyPlan = useMutation({
    mutationFn: () => api.post('/curriculum/standard-plan').then((r) => r.data.data),
    onSuccess: (r) => {
      setConfirmPlan(false)
      setMessage({
        kind: 'ok', text: t('curriculum.planApplied', { applied: r.applied.length, created: r.subjects_created }),
        extra: r.missing_grades.length ? t('curriculum.planMissing', { grades: r.missing_grades.join(', ') }) : '',
      })
      refresh()
    },
    onError: (err) => { setConfirmPlan(false); fail(err) },
  })

  const copyGrade = useMutation({
    mutationFn: () => api.post('/curriculum/copy', { from_grade_level_id: Number(copy.from), to_grade_level_ids: copy.to }),
    onSuccess: (r) => { setCopy(null); setMessage({ kind: 'ok', text: t('curriculum.copied', { count: r.data.data.copied }), extra: '' }); refresh() },
    onError: (err) => { setCopy(null); fail(err) },
  })

  if (!data) return <p className="text-slate-500">{t('common.loading')}</p>

  const name = (x) => x[`name_${i18n.language}`] || x.name_ar
  const total = (gradeId) => data.subjects.reduce((sum, s) => sum + Number(values[key(gradeId, s.id)] || 0), 0)
  const status = (sum, target) => (sum === target ? ['curriculum.complete', 'text-green-700'] : sum < target ? ['curriculum.missing', 'text-amber-700'] : ['curriculum.over', 'text-red-700'])
  const edit = (gradeId, subjectId) => (e) => { setDirty(true); setMessage({ kind: '', text: '', extra: '' }); setValues({ ...values, [key(gradeId, subjectId)]: e.target.value }) }

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('curriculum.title')}</h1>
        <div className="flex flex-wrap items-center gap-2">
          <button type="button" disabled={dirty} className="rounded border px-3 py-1.5 disabled:opacity-50" onClick={() => setCopy({ from: String(data.grades[0]?.id ?? ''), to: [] })}>{t('curriculum.copyGrade')}</button>
          <button type="button" disabled={dirty} className="rounded border px-3 py-1.5 disabled:opacity-50" onClick={() => setConfirmPlan(true)}>{t('curriculum.standardPlan')}</button>
          <button type="button" disabled={!dirty || save.isPending} className="rounded bg-brand px-5 py-1.5 text-white disabled:opacity-50" onClick={() => save.mutate()}>{t('admin.actions.save')}</button>
        </div>
      </div>
      <p className="text-sm text-slate-600">{t('curriculum.help')} <Link to="/admin/subjects" className="text-brand underline">{t('curriculum.subjectsLink')}</Link></p>

      {message.kind === 'error' && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{message.text}</p>}
      {message.kind === 'ok' && <p role="status" className="rounded bg-green-50 p-3 text-green-800">{message.text}</p>}
      {message.extra && <p className="rounded bg-amber-50 p-3 text-sm text-amber-800">{message.extra}</p>}

      <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-100">
            <tr>
              <th className="sticky start-0 bg-slate-100 p-2 text-start font-medium">{t('curriculum.subject')}</th>
              {data.grades.map((g) => <th key={g.id} className="p-2 text-center font-medium">{name(g)}</th>)}
            </tr>
          </thead>
          <tbody>
            {data.subjects.map((s) => (
              <tr key={s.id} className="border-t">
                <td className="sticky start-0 bg-white p-2 font-medium">{name(s)}</td>
                {data.grades.map((g) => (
                  <td key={g.id} className="p-1 text-center">
                    <input
                      type="number" min="0" max="40" dir="ltr" aria-label={`${name(s)} / ${name(g)}`} className="w-14 rounded border p-1 text-center"
                      value={values[key(g.id, s.id)] ?? ''} onChange={edit(g.id, s.id)}
                    />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t-2 bg-slate-50">
              <td className="sticky start-0 bg-slate-50 p-2 font-bold">{t('curriculum.total')}</td>
              {data.grades.map((g) => {
                const sum = total(g.id)
                const target = g.weekly_target ?? data.periods_per_week
                const [label, color] = status(sum, target)
                return (
                  <td key={g.id} className="p-2 text-center">
                    <span className="block font-bold">{sum}</span>
                    <span className={`block text-xs ${color}`}>{t(label, { count: Math.abs(target - sum) })}</span>
                  </td>
                )
              })}
            </tr>
          </tfoot>
        </table>
      </div>

      <ClassTeacherGrades grades={data.grades} subjects={data.subjects} cells={data.cells} onSaved={refresh} />

      <ConfirmDialog open={confirmPlan} message={t('curriculum.confirmPlan')} confirmLabel={t('curriculum.apply')} danger={false} onConfirm={() => applyPlan.mutate()} onCancel={() => setConfirmPlan(false)} />

      <Modal open={copy !== null} title={t('curriculum.copyGrade')} onClose={() => setCopy(null)}>
        {copy && (
          <div className="space-y-3">
            <label className="block text-sm">
              {t('curriculum.copyFrom')}
              <select className="mt-1 w-full rounded border p-2" value={copy.from} onChange={(e) => setCopy({ from: e.target.value, to: copy.to.filter((id) => String(id) !== e.target.value) })}>
                {data.grades.map((g) => <option key={g.id} value={g.id}>{name(g)}</option>)}
              </select>
            </label>
            <fieldset className="grid grid-cols-2 gap-1 text-sm">
              <legend className="mb-1">{t('curriculum.copyTo')}</legend>
              {data.grades.filter((g) => String(g.id) !== copy.from).map((g) => (
                <label key={g.id} className="flex items-center gap-2">
                  <input
                    type="checkbox" checked={copy.to.includes(g.id)}
                    onChange={(e) => setCopy({ ...copy, to: e.target.checked ? [...copy.to, g.id].sort((a, b) => a - b) : copy.to.filter((id) => id !== g.id) })}
                  />
                  {name(g)}
                </label>
              ))}
            </fieldset>
            <p className="text-xs text-slate-500">{t('curriculum.copyWarning')}</p>
            <div className="flex justify-end gap-2">
              <button type="button" className="rounded border px-3 py-1.5" onClick={() => setCopy(null)}>{t('admin.actions.cancel')}</button>
              <button type="button" disabled={copy.to.length === 0 || copyGrade.isPending} className="rounded bg-brand px-3 py-1.5 text-white disabled:opacity-50" onClick={() => copyGrade.mutate()}>{t('curriculum.copy')}</button>
            </div>
          </div>
        )}
      </Modal>
    </section>
  )
}
