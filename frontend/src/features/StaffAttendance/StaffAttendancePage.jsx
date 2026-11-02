import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import Modal from '../../components/shared/Modal'
import { useAuth } from '../Auth/AuthProvider'
import CheckInCard from './CheckInCard'

const STATUS_STYLE = { present: 'bg-green-100 text-green-800', late: 'bg-amber-100 text-amber-800', absent: 'bg-red-100 text-red-700', leave: 'bg-blue-100 text-blue-800' }

function EditDialog({ row, date, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ status: row.status ?? 'present', check_in: row.check_in ?? '', check_out: row.check_out ?? '', note: row.note ?? '' })
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => api.put(`/staff-attendance/${row.user.id}`, { date, status: form.status, check_in: form.check_in || null, check_out: form.check_out || null, note: form.note || null }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['staff-attendance'] }); onClose() },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')),
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const timed = ['present', 'late'].includes(form.status)
  return (
    <Modal open title={t('staffAttendance.editFor', { name: row.user.name })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('staffAttendance.cols.state')}
          <select className="mt-1 w-full rounded border p-2" value={form.status} onChange={set('status')}>{['present', 'absent', 'leave'].map((s) => <option key={s} value={s}>{t(`staffAttendance.status.${s}`)}</option>)}</select>
        </label>
        {timed && (
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm">{t('staffAttendance.cols.in')}<input type="time" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.check_in} onChange={set('check_in')} /></label>
            <label className="block text-sm">{t('staffAttendance.cols.out')}<input type="time" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.check_out} onChange={set('check_out')} /></label>
          </div>
        )}
        <label className="block text-sm">{t('staffAttendance.note')}<input className="mt-1 w-full rounded border p-2" value={form.note} onChange={set('note')} /></label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

// The staff's working day: who arrived, who was late, who is absent. The principal can fix a day.
export default function StaffAttendancePage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [edit, setEdit] = useState(null)
  const { data, isLoading } = useQuery({ queryKey: ['staff-attendance', 'day', date], queryFn: async () => (await api.get('/staff-attendance/day', { params: { date } })).data })
  const canEdit = user?.role === 'admin'
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('staffAttendance.title')}</h1>
      <CheckInCard />
      <label className="block text-sm">{t('staffAttendance.date')}<input type="date" className="ms-2 rounded border p-1.5" value={date} onChange={(e) => e.target.value && setDate(e.target.value)} /></label>
      {isLoading || !data ? <p className="text-slate-500">{t('common.loading')}</p> : (
        <>
          {!data.workday && <p className="rounded border bg-white p-3 text-sm text-slate-600">{t('staffAttendance.dayOff')}</p>}
          <div className="overflow-x-auto rounded-lg border bg-white">
            <table className="w-full text-start text-sm">
              <thead className="bg-slate-50"><tr>{['name', 'state', 'in', 'out'].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`staffAttendance.cols.${c}`)}</th>)}<th /></tr></thead>
              <tbody>
                {data.data.map((r) => (
                  <tr key={r.user.id} className="border-t">
                    <td className="p-2 font-medium">{r.user.name}</td>
                    <td className="p-2">{r.status ? <span className={`rounded-full px-2 py-0.5 text-xs ${STATUS_STYLE[r.status]}`}>{t(`staffAttendance.status.${r.status}`)}</span> : <span className="text-slate-400">—</span>}{r.note && <span className="ms-2 text-xs text-slate-500">{r.note}</span>}</td>
                    <td className="p-2" dir="ltr">{r.check_in ?? '—'}</td>
                    <td className="p-2" dir="ltr">{r.check_out ?? '—'}</td>
                    <td className="p-2 text-end">{canEdit && <button type="button" className="rounded border px-2 py-1 text-brand" onClick={() => setEdit(r)}>{t('admin.actions.edit')}</button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
      {edit && <EditDialog row={edit} date={date} onClose={() => setEdit(null)} />}
    </section>
  )
}
