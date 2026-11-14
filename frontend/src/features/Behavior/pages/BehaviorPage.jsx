import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import EvaluateDialog from '../components/EvaluateDialog'
import { DateInput } from '../../../components/shared/DateInput'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const today = () => new Date().toISOString().slice(0, 10)

// Teacher / admin: pick a section and a date, evaluate each student for that week, open a student's results.
export default function BehaviorPage({ base }) {
  const { t, i18n } = useTranslation()
  const [classroomId, setClassroomId] = useState('')
  const [date, setDate] = useState(today())
  const [editing, setEditing] = useState(null)

  const classrooms = useQuery({ queryKey: ['behavior', 'classrooms'], queryFn: async () => (await api.get('/behavior/classrooms')).data.data })
  const active = classroomId || classrooms.data?.[0]?.id
  const week = useQuery({
    queryKey: ['behavior', 'week', active, date],
    queryFn: async () => (await api.get('/behavior/week', { params: { classroom_id: active, date } })).data.data,
    enabled: Boolean(active),
  })

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('behavior.title')}</h1>
        {base === '/admin' && <Link to="/admin/behavior-criteria" className="rounded border px-3 py-1.5 text-sm text-brand">{t('behavior.criteriaTitle')}</Link>}
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <label className="text-sm">{t('academic.fields.classroom')}
          <select className="mt-1 block rounded border p-2" value={active ?? ''} onChange={(e) => setClassroomId(e.target.value)}>
            {(classrooms.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </label>
        <label className="text-sm">{t('behavior.date')}<DateInput max={today()} className="mt-1 block rounded border p-2" value={date} onChange={(e) => setDate(e.target.value || today())} /></label>
      </div>
      {week.data && <p className="text-sm text-slate-600">{t('behavior.weekOf', { date: week.data.week_starts_on })}</p>}
      {classrooms.isSuccess && classrooms.data.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('behavior.noClassrooms')}</p>}
      <ul className="space-y-2">
        {(week.data?.students ?? []).map((s) => (
          <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 rounded border bg-white p-3">
            <span>{s[`name_${i18n.language}`] || s.name_ar} {s.special_count > 0 && <span title={t('behavior.special.badgeHint')} className="ms-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-800">★ {t('behavior.special.badge', { count: s.special_count })}</span>} {s.evaluation && <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800">{t('behavior.done')}</span>}</span>
            <span className="flex gap-2">
              <InteractiveHoverButton type="button" className="px-3 py-1 text-sm" onClick={() => setEditing(s)}>{t('behavior.evaluate')}</InteractiveHoverButton>
              <Link to={`${base}/behavior/students/${s.id}`} className="rounded border px-3 py-1 text-sm text-brand">{t('behavior.results')}</Link>
            </span>
          </li>
        ))}
      </ul>
      {editing && <EvaluateDialog key={`${editing.id}-${date}`} student={editing} date={date} onClose={() => setEditing(null)} />}
    </section>
  )
}
