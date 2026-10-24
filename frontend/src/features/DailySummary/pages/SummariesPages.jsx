import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../Auth/AuthProvider'
import SummaryList from '../components/SummaryList'

export function StudentSummariesPage() {
  const { t } = useTranslation()
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('dailySummary.title')}</h1>
      <SummaryList endpoint="/daily-summaries/me" />
    </section>
  )
}

export function ChildSummariesPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const child = (user?.children ?? []).find((c) => String(c.id) === id)
  const name = child ? child[`name_${i18n.language}`] || child.name_ar : ''
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('dailySummary.childTitle', { name })}</h1>
      <SummaryList endpoint={`/students/${id}/daily-summaries`} />
    </section>
  )
}
