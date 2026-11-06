import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useAuth } from '../../Auth/AuthProvider'
import QuizHistory from '../components/QuizHistory'

export default function ChildQuizzesPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const child = (user?.children ?? []).find((c) => String(c.id) === id)
  const name = child ? child[`name_${i18n.language}`] || child.name_ar : ''
  const { data } = useQuery({ queryKey: ['quizzes', 'child', id], queryFn: async () => (await api.get(`/students/${id}/quizzes`)).data.data })
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('quiz.childTitle', { name })}</h1>
      <QuizHistory rows={data} />
    </section>
  )
}
