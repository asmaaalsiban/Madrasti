import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useAuth } from '../../Auth/AuthProvider'
import Statement from '../components/Statement'

// A parent reads the fee statement of one child.
export default function ChildFeesPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const child = (user?.children ?? []).find((c) => String(c.id) === id)
  const name = child ? child[`name_${i18n.language}`] || child.name_ar : ''
  const { data, isLoading } = useQuery({ queryKey: ['finance', 'child', id], queryFn: async () => (await api.get(`/students/${id}/fees`)).data.data })
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('finance.childTitle', { name })}</h1>
      {isLoading ? <p>{t('common.loading')}</p> : <Statement data={data} />}
    </section>
  )
}
