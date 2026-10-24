import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// Read-only list of the homework of a section (student and parent): the next due first is not assumed, the API orders it by due date.
export default function HomeworkList({ endpoint }) {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['homework', endpoint], queryFn: async () => (await api.get(endpoint)).data.data })
  const subject = (s) => s[`name_${i18n.language}`] || s.name_ar

  if (isLoading) return <p>{t('common.loading')}</p>
  if (!data?.length) return <p className="rounded bg-white p-4 text-slate-600">{t('homework.none')}</p>
  return (
    <ul className="space-y-3">
      {data.map((h) => (
        <li key={h.id} className="space-y-1 rounded-lg border bg-white p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="font-bold">{h.title}</span>
            <span className="rounded-full bg-brand/10 px-2 py-0.5 text-xs text-brand">{subject(h.subject)}</span>
          </div>
          {h.description && <p className="whitespace-pre-line text-sm text-slate-700">{h.description}</p>}
          <p className="text-xs text-slate-500">{t('homework.dueOn', { date: h.due_on })} · {h.teacher.name}</p>
        </li>
      ))}
    </ul>
  )
}
