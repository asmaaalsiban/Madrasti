import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import ExportButton from '../../../components/shared/ExportButton'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import StatusDates from '../components/StatusDates'
import { useStudentName } from '../components/useStudentName'
import { DateInput } from '../../../components/shared/DateInput'

// Per student: how many days absent / late in the period; the number opens the dates behind it.
export default function AttendanceReportPage() {
  const { t } = useTranslation()
  const nameOf = useStudentName()
  const [filters, setFilters] = useState({ classroom_id: '', from: '', to: '' })
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(null) // { student, status }

  const params = { page, ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')) }
  const rooms = useQuery({ queryKey: ['attendance', 'classrooms'], queryFn: async () => (await api.get('/attendance/classrooms')).data.data })
  const report = useQuery({
    queryKey: ['attendance', 'report', params],
    queryFn: async () => (await api.get('/attendance/report', { params })).data,
    placeholderData: keepPreviousData,
  })
  const days = useQuery({
    queryKey: ['attendance', 'days', open?.student.id, open?.status, filters.from, filters.to],
    queryFn: async () => (await api.get(`/attendance/students/${open.student.id}`, {
      params: { status: open.status, ...(filters.from && { from: filters.from }), ...(filters.to && { to: filters.to }) },
    })).data.data,
    enabled: open !== null,
  })

  const set = (key) => (e) => { setPage(1); setFilters({ ...filters, [key]: e.target.value }) }
  const rows = report.data?.data ?? []
  const meta = report.data?.meta
  const number = (row, status) => (row[status] > 0 ? (
    <button type="button" aria-label={`${t(`attendance.${status}`)}: ${row[status]}`} className="font-bold text-brand underline" onClick={() => setOpen({ student: row.student, status })}>{row[status]}</button>
  ) : 0)

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('attendance.reportTitle')}</h1>
        <ExportButton path="/attendance/report/export" params={Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))} fallbackName="attendance.xlsx" />
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <label className="text-sm">
          {t('attendance.classroom')}
          <select className="mt-1 block rounded border p-2" value={filters.classroom_id} onChange={set('classroom_id')}>
            <option value="">{t('attendance.allClassrooms')}</option>
            {(rooms.data ?? []).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </select>
        </label>
        <label className="text-sm">
          {t('attendance.from')}
          <DateInput className="mt-1 block rounded border p-2" value={filters.from} onChange={set('from')} />
        </label>
        <label className="text-sm">
          {t('attendance.to')}
          <DateInput className="mt-1 block rounded border p-2" value={filters.to} onChange={set('to')} />
        </label>
      </div>
      {meta && <p className="text-sm text-slate-500" dir="ltr">{meta.from} → {meta.to}</p>}

      <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-100">
            <tr>
              {['student', 'classroom', 'absentDays', 'lateDays'].map((k) => <th key={k} className="p-2 text-start font-medium sm:p-3">{t(`attendance.${k}`)}</th>)}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.student.id} className="border-t">
                <td className="p-2 sm:p-3">{nameOf(row.student)}</td>
                <td className="p-2 sm:p-3">{row.classroom}</td>
                <td className="p-2 sm:p-3">{number(row, 'absent')}</td>
                <td className="p-2 sm:p-3">{number(row, 'late')}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {!report.isLoading && rows.length === 0 && <p className="p-6 text-center text-slate-500">{t('attendance.noAbsences')}</p>}
      </div>

      {meta?.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <button type="button" className="rounded border px-3 py-1 disabled:opacity-50" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('admin.actions.prev')}</button>
          <span>{page} / {meta.last_page}</span>
          <button type="button" className="rounded border px-3 py-1 disabled:opacity-50" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>{t('admin.actions.next')}</button>
        </div>
      )}

      <Modal open={open !== null} title={open ? t(`attendance.${open.status}Dates`, { name: nameOf(open.student) }) : ''} onClose={() => setOpen(null)}>
        {days.data ? <StatusDates records={days.data.records} /> : <p className="text-sm text-slate-500">{t('attendance.loading')}</p>}
        <div className="flex justify-end pt-4">
          <button type="button" className="rounded border px-3 py-1.5" onClick={() => setOpen(null)}>{t('attendance.close')}</button>
        </div>
      </Modal>
    </section>
  )
}
