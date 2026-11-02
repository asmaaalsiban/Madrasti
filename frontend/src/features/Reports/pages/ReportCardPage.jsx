import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../Auth/AuthProvider'
import ReportCard from '../components/ReportCard'

// The student's own report card.
export function MyReportCardPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold print:hidden">{t('reportCard.title')}</h1>
      {user?.student_id ? <ReportCard studentId={user.student_id} /> : <p className="text-slate-600">{t('studentGrades.noRecord')}</p>}
    </section>
  )
}

// A parent's view of one child, or the admin's view of any student (`/…/:id`).
export default function ReportCardPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold print:hidden">{t('reportCard.title')}</h1>
      <ReportCard studentId={id} />
    </section>
  )
}
