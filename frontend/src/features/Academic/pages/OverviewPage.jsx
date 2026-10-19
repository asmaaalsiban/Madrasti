import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

const CARDS = [
  ['academic_years', 'admin.nav.academicYears'],
  ['grade_levels', 'admin.nav.gradeLevels'],
  ['classrooms', 'admin.nav.classrooms'],
  ['subjects', 'admin.nav.subjects'],
]

export default function OverviewPage() {
  const { t } = useTranslation()
  const { data } = useQuery({ queryKey: ['admin-overview'], queryFn: () => api.get('/admin/overview').then((r) => r.data.data) })
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('academic.overview.title')}</h1>
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        {CARDS.map(([key, label]) => (
          <div key={key} className="rounded-lg border-t-4 border-brand bg-white p-4 shadow-sm">
            <p className="text-3xl font-bold">{data?.[key] ?? '–'}</p>
            <p className="mt-1 text-sm text-slate-600">{t(label)}</p>
          </div>
        ))}
      </div>
    </section>
  )
}
