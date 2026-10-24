import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// What each lesson covered, grouped by day (student and parent).
export default function SummaryList({ endpoint }) {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['daily-summaries', endpoint], queryFn: async () => (await api.get(endpoint)).data.data })
  const subject = (s) => s[`name_${i18n.language}`] || s.name_ar

  if (isLoading) return <p>{t('common.loading')}</p>
  if (!data?.length) return <p className="rounded bg-white p-4 text-slate-600">{t('dailySummary.none')}</p>
  const days = Object.entries(Object.groupBy(data, (s) => s.date))
  return (
    <div className="space-y-4">
      {days.map(([date, rows]) => (
        <section key={date} className="space-y-2">
          <h2 dir="ltr" className="text-start font-bold text-brand">{date}</h2>
          <ul className="space-y-2">
            {rows.map((s) => (
              <li key={s.id} className="rounded-lg border bg-white p-3">
                <p className="font-medium">{subject(s.subject)}</p>
                <p className="whitespace-pre-line text-sm text-slate-700">{s.summary}</p>
                <p className="text-xs text-slate-500">{s.teacher.name}</p>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  )
}
