import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import StatusDates from '../../Attendance/components/StatusDates'

// Present / late / absent totals of the school year for one child, with the dates on demand.
export default function ChildAttendance({ studentId }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const { data } = useQuery({
    queryKey: ['attendance', 'child', studentId],
    queryFn: async () => (await api.get(`/attendance/students/${studentId}`, { params: {} })).data.data,
  })
  if (!data) return null
  const { absent, late } = data.summary
  const missed = data.records.filter((r) => r.status !== 'present')
  return (
    <div className="space-y-2 text-sm">
      <div className="grid grid-cols-2 gap-2 text-center">
        <div className="rounded-2xl bg-red-50 p-2"><p className="text-xl font-extrabold text-red-700">{absent}</p><p className="text-xs text-slate-600">{t('attendance.absentLabel')}</p></div>
        <div className="rounded-2xl bg-amber-50 p-2"><p className="text-xl font-extrabold text-amber-700">{late}</p><p className="text-xs text-slate-600">{t('attendance.lateLabel')}</p></div>
      </div>
      <span className="sr-only">{t('attendance.childSummary', { absent, late })}</span>
      {missed.length > 0 && (
        <>
          <button type="button" className="text-brand underline" aria-expanded={open} onClick={() => setOpen(!open)}>
            {t(open ? 'attendance.hideDates' : 'attendance.showDates')}
          </button>
          {open && <StatusDates records={missed} />}
        </>
      )}
    </div>
  )
}
