import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { today } from '../../../lib/dates'
import { useStudentName } from '../components/useStudentName'
import { DateInput } from '../../../components/shared/DateInput'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const STATUSES = ['present', 'late', 'absent']
const COUNT_BOX = { present: 'bg-green-50 text-green-800', late: 'bg-yellow-50 text-yellow-800', absent: 'bg-red-50 text-red-800' }
const ACTIVE = { present: 'bg-green-700 text-white', late: 'bg-yellow-400 text-black', absent: 'bg-red-600 text-white' }

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
    <section className="space-y-4 pb-24 md:pb-0">
      <h1 className="text-xl font-bold">{t('attendance.takeTitle')}</h1>
      <div className="grid grid-cols-2 gap-3 md:flex md:flex-wrap md:items-end">
        <label className="col-span-2 text-sm md:col-span-1">
          {t('attendance.classroom')}
          <select className="mt-1 block w-full rounded border p-2 md:w-auto" value={activeRoom ?? ''} onChange={(e) => { dirty.current = false; setSaved(false); setError(''); setClassroomId(Number(e.target.value)) }}>
            {(rooms.data ?? []).map((r) => <option key={r.id} value={r.id}>{[r.name, label(r)].filter(Boolean).join(' · ')}</option>)}
          </select>
        </label>
        <label className="text-sm">
          {t('attendance.date')}
          <DateInput className="mt-1 block w-full rounded border p-2 md:w-auto" value={date} max={today()} onChange={(e) => { dirty.current = false; setSaved(false); setError(''); setDate(e.target.value) }} />
        </label>
      </div>

      {roster.data?.recorded && <p className="rounded bg-blue-50 p-3 text-sm text-blue-800">{t('attendance.alreadyRecorded')}</p>}
      {error && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{error}</p>}
      {saved && <p role="status" className="rounded bg-green-50 p-3 text-green-800">{t('attendance.saved')}</p>}

      {/* A phone: the day's counts as three boxes, then a card per student with three big buttons; the theme sets rounded/shadow/bg without a
          breakpoint, so the cards use max-md: classes and the list box is `contents` below md. */}
      {students.length > 0 && (
        <div className="grid grid-cols-3 gap-2 md:hidden" data-testid="attendance-counts">
          {STATUSES.map((status) => (
            <div key={status} className={`rounded-xl p-2 text-center ${COUNT_BOX[status]}`}><div className="text-2xl font-bold">{count(status)}</div><div className="text-xs">{t(`attendance.${status}`)}</div></div>
          ))}
        </div>
      )}
      <ul className="rounded-lg bg-white shadow-sm max-md:contents md:divide-y">
        {students.map((s, i) => (
          <li key={s.id} className="max-md:mb-3 max-md:rounded-[26px] max-md:border max-md:bg-white max-md:shadow-[var(--shadow-flat)]">
            <div role="group" aria-label={nameOf(s)} className="flex flex-col gap-3 p-3 md:flex-row md:flex-wrap md:items-center md:justify-between md:gap-2">
              <span className="flex items-center gap-3">
                <span className="w-6 text-center text-sm text-slate-500">{i + 1}</span>
                <span className="font-medium md:font-normal">{nameOf(s)}</span>
              </span>
              <span className="grid grid-cols-3 gap-2 md:flex md:gap-1">
                {STATUSES.map((status) => {
                  const on = (statuses[s.id] ?? 'present') === status
                  return (
                    <button
                      key={status}
                      type="button"
                      aria-pressed={on}
                      className={`rounded border px-3 py-1.5 text-sm max-md:min-h-12 max-md:text-base max-md:font-medium ${on ? ACTIVE[status] : 'bg-white'}`}
                      onClick={() => { dirty.current = true; setSaved(false); setStatuses({ ...statuses, [s.id]: status }) }}
                    >
                      {t(`attendance.${status}`)}
                    </button>
                  )
                })}
              </span>
            </div>
          </li>
        ))}
      </ul>

      {students.length > 0 && (
        <div className="fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-30 flex items-center justify-between gap-3 border-t bg-white/95 p-3 backdrop-blur md:static md:flex-wrap md:border-0 md:bg-transparent md:p-0 md:backdrop-blur-none">
          <p className="min-w-0 flex-1 text-xs text-slate-600 md:text-sm">
            {t('attendance.summary', { present: count('present'), late: count('late'), absent: count('absent') })}
          </p>
          <InteractiveHoverButton type="button" disabled={save.isPending} className="min-h-11 shrink-0 px-6 py-2 font-semibold disabled:opacity-60 md:min-h-0 md:rounded md:px-5 md:font-normal" onClick={() => save.mutate()}>
            {t('admin.actions.save')}
          </InteractiveHoverButton>
        </div>
      )}
    </section>
  )
}
