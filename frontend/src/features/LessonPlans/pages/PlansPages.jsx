import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useAuth } from '../../Auth/AuthProvider'
import PlanCard from '../components/PlanCard'

function Plans({ endpoint }) {
  const { t } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['lesson-plans', endpoint], queryFn: async () => (await api.get(endpoint)).data.data })
  if (isLoading) return <p>{t('common.loading')}</p>
  if (!data?.length) return <p className="rounded bg-white p-4 text-slate-600">{t('lessonPlans.none')}</p>
  return <ul className="space-y-3">{data.map((p) => <PlanCard key={p.id} plan={p} />)}</ul>
}

export function StudentPlansPage() {
  const { t } = useTranslation()
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('lessonPlans.title')}</h1>
      <Plans endpoint="/lesson-plans/me" />
    </section>
  )
}

export function ChildPlansPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const child = (user?.children ?? []).find((c) => String(c.id) === id)
  const name = child ? child[`name_${i18n.language}`] || child.name_ar : ''
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('lessonPlans.childTitle', { name })}</h1>
      <Plans endpoint={`/students/${id}/lesson-plans`} />
    </section>
  )
}
