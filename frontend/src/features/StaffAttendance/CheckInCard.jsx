import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

// "I arrived" / "I left": the staff member records their own working day.
export default function CheckInCard() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const { data } = useQuery({ queryKey: ['staff-attendance', 'me'], queryFn: async () => (await api.get('/staff-attendance/me')).data.data })
  const act = useMutation({
    mutationFn: (path) => api.post(`/staff-attendance/${path}`),
    onSuccess: () => { setError(''); client.invalidateQueries({ queryKey: ['staff-attendance'] }) },
    onError: (err) => setError(err.response?.data?.errors?.date?.[0] || err.response?.data?.message || t('admin.actions.error')),
  })
  if (!data || !data.workday) return null
  const today = data.today
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-white p-4" aria-label={t('staffAttendance.myDay')}>
      <div>
        <p className="font-bold">{t('staffAttendance.myDay')}</p>
        <p className="text-sm text-slate-600">
          {!today?.check_in && t('staffAttendance.notYet', { time: data.starts_at })}
          {today?.check_in && <>{t(`staffAttendance.status.${today.status}`)} · {t('staffAttendance.arrived', { time: today.check_in })}{today.check_out ? ` · ${t('staffAttendance.left', { time: today.check_out })}` : ''}</>}
        </p>
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
      </div>
      {!today?.check_in && <InteractiveHoverButton type="button" disabled={act.isPending} className="px-4 py-2 disabled:opacity-50" onClick={() => act.mutate('check-in')}>{t('staffAttendance.checkIn')}</InteractiveHoverButton>}
      {today?.check_in && !today.check_out && <InteractiveHoverButton variant="outline" type="button" disabled={act.isPending} className="px-4 py-2 text-brand disabled:opacity-50" onClick={() => act.mutate('check-out')}>{t('staffAttendance.checkOut')}</InteractiveHoverButton>}
    </div>
  )
}
