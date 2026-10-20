import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { today } from '../../../lib/dates'
import { useStudentName } from '../components/useStudentName'

const STATUSES = ['present', 'late', 'absent']
const ACTIVE = { present: 'bg-green-600 text-white', late: 'bg-yellow-400 text-black', absent: 'bg-red-600 text-white' }

// The homeroom teacher (or admin) records the day: everybody starts present, only the exceptions are touched.
export default function TakeAttendancePage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const nameOf = useStudentName()
  const [classroomId, setClassroomId] = useState('')
  const [date, setDate] = useState(today())
  const [statuses, setStatuses] = useState({})
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState('')
  const dirty = useRef(false) // unsaved taps must survive a background refetch

  const rooms = useQuery({ queryKey: ['attendance', 'classrooms'], queryFn: async () => (await api.get('/attendance/classrooms')).data.data })
  const activeRoom = classroomId || rooms.data?.[0]?.id
  const roster = useQuery({
    queryKey: ['attendance', 'roster', activeRoom, date],
    queryFn: async () => (await api.get('/attendance', { params: { classroom_id: activeRoom, date } })).data.data,
    enabled: Boolean(activeRoom) && Boolean(date),
  })

  useEffect(() => {
    if (!roster.data || dirty.current) return
    setStatuses(Object.fromEntries(roster.data.students.map((s) => [s.id, s.status ?? 'present'])))
  }, [roster.data])

  const save = useMutation({
    mutationFn: () => api.put('/attendance', {
      classroom_id: activeRoom,
      date,
      records: roster.data.students.map((s) => ({ student_id: s.id, status: statuses[s.id] ?? 'present' })),
    }),
    onSuccess: () => {
      setError('')
      dirty.current = false
      client.invalidateQueries({ queryKey: ['attendance'] })
      setSaved(true)
    },
    onError: (err) => {
      setSaved(false)
      setError(err.response?.data?.message || t('admin.actions.error'))
    },
  })

  if (rooms.isSuccess && rooms.data.length === 0) {
    return <p className="rounded bg-white p-4 text-slate-600">{t('attendance.noClassrooms')}</p>
  }

  const students = roster.data?.students ?? []
  const count = (status) => students.filter((s) => (statuses[s.id] ?? 'present') === status).length
  const label = (r) => r.grade_level?.[`name_${i18n.language}`]

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('attendance.takeTitle')}</h1>
      <div className="flex flex-wrap items-end gap-3">
        <label className="text-sm">
          {t('attendance.classroom')}
          <select className="mt-1 block rounded border p-2" value={activeRoom ?? ''} onChange={(e) => { dirty.current = false; setSaved(false); setError(''); setClassroomId(Number(e.target.value)) }}>
            {(rooms.data ?? []).map((r) => <option key={r.id} value={r.id}>{[r.name, label(r)].filter(Boolean).join(' · ')}</option>)}
          </select>
        </label>
        <label className="text-sm">
          {t('attendance.date')}
          <input type="date" dir="ltr" className="mt-1 block rounded border p-2" value={date} max={today()} onChange={(e) => { dirty.current = false; setSaved(false); setError(''); setDate(e.target.value) }} />
        </label>
      </div>

      {roster.data?.recorded && <p className="rounded bg-blue-50 p-3 text-sm text-blue-800">{t('attendance.alreadyRecorded')}</p>}
      {error && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{error}</p>}
      {saved && <p role="status" className="rounded bg-green-50 p-3 text-green-800">{t('attendance.saved')}</p>}

      <ul className="divide-y rounded-lg bg-white shadow-sm">
        {students.map((s, i) => (
          <li key={s.id} role="group" aria-label={nameOf(s)} className="flex flex-wrap items-center justify-between gap-2 p-3">
            <span className="flex items-center gap-3">
              <span className="w-6 text-center text-sm text-slate-500">{i + 1}</span>
              <span>{nameOf(s)}</span>
            </span>
            <span className="flex gap-1">
              {STATUSES.map((status) => {
                const on = (statuses[s.id] ?? 'present') === status
                return (
                  <button
                    key={status}
                    type="button"
                    aria-pressed={on}
                    className={`rounded border px-3 py-1.5 text-sm ${on ? ACTIVE[status] : 'bg-white'}`}
                    onClick={() => { dirty.current = true; setSaved(false); setStatuses({ ...statuses, [s.id]: status }) }}
                  >
                    {t(`attendance.${status}`)}
                  </button>
                )
              })}
            </span>
          </li>
        ))}
      </ul>

      {students.length > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-slate-600">
            {t('attendance.summary', { present: count('present'), late: count('late'), absent: count('absent') })}
          </p>
          <button type="button" disabled={save.isPending} className="rounded bg-brand px-5 py-2 text-white disabled:opacity-60" onClick={() => save.mutate()}>
            {t('admin.actions.save')}
          </button>
        </div>
      )}
    </section>
  )
}
