import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { useAuth } from '../Auth/AuthProvider'
import RideCard from './RideCard'

// The bus of a student: their own (student) or one of the parent's children.
export default function MyTransportPage() {
  const { t, i18n } = useTranslation()
  const { id: childId } = useParams()
  const { user } = useAuth()
  const studentId = childId ?? user?.student_id
  const child = (user?.children ?? []).find((c) => String(c.id) === childId)
  const name = child ? child[`name_${i18n.language}`] || child.name_ar : ''
  const { data, isLoading } = useQuery({ queryKey: ['transport', 'student', studentId], enabled: Boolean(studentId), queryFn: async () => (await api.get(`/students/${studentId}/transport`)).data.data })
  return (
    <section className="space-y-4">
      <h1>{childId ? t('transport.childTitle', { name }) : t('transport.myTitle')}</h1>
      {isLoading ? <p className="text-slate-500">{t('common.loading')}</p> : <RideCard ride={data} />}
    </section>
  )
}
