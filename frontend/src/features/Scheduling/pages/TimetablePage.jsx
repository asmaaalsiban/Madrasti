import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import GenerateTimetableDialog from '../components/GenerateTimetableDialog'

// The week as a grid: periods are rows, school days are columns.
export default function TimetablePage() {
  const { t, i18n } = useTranslation()
  const [mode, setMode] = useState('classroom')
  const [classroomId, setClassroomId] = useState('')
  const [teacherId, setTeacherId] = useState('')
  const [generate, setGenerate] = useState(false)

  const classrooms = useQuery({ queryKey: ['timetable', 'classrooms'], queryFn: async () => (await api.get('/classrooms', { params: { per_page: 100 } })).data.data })
  const teachers = useQuery({ queryKey: ['timetable', 'teachers'], queryFn: async () => (await api.get('/staff', { params: { per_page: 100, category: 'teaching' } })).data.data })
  const activeClassroom = classroomId || classrooms.data?.[0]?.id
  const activeTeacher = teacherId || teachers.data?.[0]?.id
  const params = mode === 'classroom' ? (activeClassroom ? { classroom_id: Number(activeClassroom) } : null) : (activeTeacher ? { teacher_id: Number(activeTeacher) } : null)

  const grid = useQuery({ queryKey: ['timetable', 'grid', params], queryFn: async () => (await api.get('/timetable', { params })).data.data, enabled: params !== null })
  const periods = grid.data?.periods ?? []
  const days = [...new Set(periods.map((p) => p.day_of_week))].sort((a, b) => a - b)
  const numbers = [...new Set(periods.map((p) => p.number))].sort((a, b) => a - b)
  const periodAt = (day, number) => periods.find((p) => p.day_of_week === day && p.number === number)
  const entryAt = (day, number) => { const p = periodAt(day, number); return p && (grid.data?.entries ?? []).find((e) => e.period_id === p.id) }
  const timeOf = (number) => { const p = periods.find((x) => x.number === number); return p ? `${p.starts_at} - ${p.ends_at}` : '' }
  const subjectName = (s) => s[`name_${i18n.language}`] || s.name_ar

  const tab = (key, label) => (
    <button
      key={key} type="button" role="tab" aria-selected={mode === key}
      className={`rounded-full border px-3 py-1 text-sm ${mode === key ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => setMode(key)}
    >
      {label}
    </button>
  )

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('timetable.title')}</h1>
        <button type="button" className="rounded bg-brand px-4 py-1.5 text-white" onClick={() => setGenerate(true)}>{t('timetable.generate')}</button>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div role="tablist" className="flex gap-2">{tab('classroom', t('timetable.byClassroom'))}{tab('teacher', t('timetable.byTeacher'))}</div>
        {mode === 'classroom' ? (
          <label className="text-sm">
            {t('academic.fields.classroom')}
            <select className="mt-1 block rounded border p-2" value={activeClassroom ?? ''} onChange={(e) => setClassroomId(e.target.value)}>
              {(classrooms.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </label>
        ) : (
          <label className="text-sm">
            {t('timetable.teacher')}
            <select className="mt-1 block rounded border p-2" value={activeTeacher ?? ''} onChange={(e) => setTeacherId(e.target.value)}>
              {(teachers.data ?? []).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
            </select>
          </label>
        )}
      </div>

      {grid.isSuccess && periods.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('timetable.noPeriods')}</p>}

      {periods.length > 0 && (
        <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
          <table className="w-full border-collapse text-start text-sm">
            <thead className="bg-slate-100">
              <tr>
                <th className="p-2" />
                {days.map((d) => <th key={d} className="p-2 text-center font-medium">{t(`days.${d}`)}</th>)}
              </tr>
            </thead>
            <tbody>
              {numbers.map((n) => (
                <tr key={n} className="border-t">
                  <td className="whitespace-nowrap p-2 text-xs">
                    <span className="block font-medium">{t('schoolDay.period', { number: n })}</span>
                    <span dir="ltr" className="block text-slate-500">{timeOf(n)}</span>
                  </td>
                  {days.map((d) => {
                    const e = entryAt(d, n)
                    return (
                      <td key={d} className="border-s p-1.5 text-center align-top">
                        {e && (
                          <div className={`rounded p-1 ${e.is_locked ? 'bg-amber-50' : 'bg-slate-50'}`}>
                            <span className="block font-medium">{subjectName(e.subject)}</span>
                            <span className="block text-xs text-slate-500">{mode === 'classroom' ? e.teacher.name : e.classroom.name}</span>
                            {e.is_locked && <span role="img" aria-label={t('timetable.locked')} className="text-xs">🔒</span>}
                          </div>
                        )}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <GenerateTimetableDialog open={generate} onClose={() => setGenerate(false)} />
    </section>
  )
}
