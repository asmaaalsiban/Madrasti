import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import TimetableGrid from './TimetableGrid'

// The week of the signed-in user, or of a student: fetched from one endpoint.
export default function WeekTimetable({ endpoint, show, emptyLabel }) {
  const { t } = useTranslation()
  const { data, isLoading, isError } = useQuery({ queryKey: ['timetable', 'week', endpoint], queryFn: async () => (await api.get(endpoint)).data.data })

  if (isLoading) return <p className="text-slate-500">{t('common.loading')}</p>
  if (isError || !data) return <p role="alert" className="text-red-700">{t('admin.actions.error')}</p>
  if (data.periods.length === 0 || data.entries.length === 0) return <p className="rounded bg-white p-4 text-slate-600">{t('timetable.notReady')}</p>

  return (
    <div className="space-y-2">
      {data.title && <p className="text-sm text-slate-600">{data.title}</p>}
      <TimetableGrid periods={data.periods} entries={data.entries} show={show} emptyLabel={emptyLabel ? t(emptyLabel) : null} />
    </div>
  )
}
