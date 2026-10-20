import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import StatusDates from '../../Attendance/components/StatusDates'

// Absent / late totals of the school year for one child, with the dates on demand.
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
    <div className="mt-2 border-t pt-2 text-sm">
      <p>{t('attendance.childSummary', { absent, late })}</p>
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
