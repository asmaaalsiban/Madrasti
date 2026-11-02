import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { useAuth } from '../Auth/AuthProvider'
import ExamList from './ExamList'

// The exams of one of the parent's children.
export default function ChildExamsPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const child = (user?.children ?? []).find((c) => String(c.id) === id)
  const name = child ? child[`name_${i18n.language}`] || child.name_ar : ''
  const { data, isLoading } = useQuery({ queryKey: ['exams', 'child', id], queryFn: async () => (await api.get(`/students/${id}/exams`)).data.data })
  return (
    <section className="space-y-4">
      <h1>{t('exams.childTitle', { name })}</h1>
      {isLoading || !data ? <p className="text-slate-500">{t('common.loading')}</p> : <ExamList sessions={data} showClass={false} />}
    </section>
  )
}
