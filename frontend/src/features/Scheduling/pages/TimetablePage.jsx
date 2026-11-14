import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import EditLessonDialog from '../components/EditLessonDialog'
import GenerateTimetableDialog from '../components/GenerateTimetableDialog'
import TimetableGrid from '../components/TimetableGrid'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// The week as a grid: periods are rows, school days are columns.
export default function TimetablePage() {
  const { t } = useTranslation()
  const [mode, setMode] = useState('classroom')
  const [classroomId, setClassroomId] = useState('')
  const [teacherId, setTeacherId] = useState('')
  const [generate, setGenerate] = useState(false)
  const [editing, setEditing] = useState(null)

  const classrooms = useQuery({ queryKey: ['timetable', 'classrooms'], queryFn: async () => (await api.get('/classrooms', { params: { per_page: 100 } })).data.data })
  const teachers = useQuery({ queryKey: ['timetable', 'teachers'], queryFn: async () => (await api.get('/staff', { params: { per_page: 100, category: 'teaching' } })).data.data })
  const activeClassroom = classroomId || classrooms.data?.[0]?.id
  const activeTeacher = teacherId || teachers.data?.[0]?.id
  const params = mode === 'classroom' ? (activeClassroom ? { classroom_id: Number(activeClassroom) } : null) : (activeTeacher ? { teacher_id: Number(activeTeacher) } : null)

  const grid = useQuery({ queryKey: ['timetable', 'grid', params], queryFn: async () => (await api.get('/timetable', { params })).data.data, enabled: params !== null })
  const periods = grid.data?.periods ?? []

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
        <InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={() => setGenerate(true)}>{t('timetable.generate')}</InteractiveHoverButton>
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

      {periods.length > 0 && <TimetableGrid periods={periods} entries={grid.data.entries} show={mode === 'classroom' ? 'teacher' : 'classroom'} emptyLabel={mode === 'classroom' ? t('timetable.empty') : null} onCellClick={mode === 'classroom' ? (period, entry) => setEditing({ period, entry }) : null} />}
      {editing && <EditLessonDialog classroomId={Number(activeClassroom)} period={editing.period} entry={editing.entry} onClose={() => setEditing(null)} />}

      <GenerateTimetableDialog open={generate} onClose={() => setGenerate(false)} />
    </section>
  )
}
