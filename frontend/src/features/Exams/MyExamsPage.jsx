import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { useAuth } from '../Auth/AuthProvider'
import ExamList from './ExamList'

// A student's exams (their section), or a teacher's: the rooms they watch and the exams of the sections they teach.
export default function MyExamsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { data, isLoading } = useQuery({ queryKey: ['exams', 'me'], queryFn: async () => (await api.get('/exams/me')).data.data })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  if (user?.role === 'student') {
    return <section className="space-y-4"><h1>{t('exams.myTitle')}</h1><ExamList sessions={data} showClass={false} /></section>
  }
  return (
    <section className="space-y-6">
      <h1>{t('exams.myTitle')}</h1>
      <div className="space-y-3"><h2 className="text-lg">{t('exams.watching')}</h2><ExamList sessions={data.watching} empty={t('exams.noneWatching')} meId={user?.id} /></div>
      <div className="space-y-3"><h2 className="text-lg">{t('exams.teaching')}</h2><ExamList sessions={data.teaching} empty={t('exams.noneTeaching')} /></div>
    </section>
  )
}
