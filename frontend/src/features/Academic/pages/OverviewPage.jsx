import { useQuery } from '@tanstack/react-query'
import useBadges from '../../Badges/useBadges'
import { Link } from 'react-router-dom'
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
  const pending = { data: useBadges().surveys }
  const { data } = useQuery({ queryKey: ['admin-overview'], queryFn: () => api.get('/admin/overview').then((r) => r.data.data) })
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('academic.overview.title')}</h1>
      {pending.data > 0 && <Link to="/admin/my-surveys" className="block rounded-lg border border-brand bg-blue-50 p-4 font-medium text-brand">{t('surveys.waiting', { count: pending.data })}</Link>}
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
