import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../Auth/AuthProvider'
import StudentBehavior from '../components/StudentBehavior'

// The student's own behaviour results.
export function MyBehaviorPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('behavior.title')}</h1>
      {user?.student_id ? <StudentBehavior studentId={user.student_id} /> : <p className="text-slate-600">{t('studentGrades.noRecord')}</p>}
    </section>
  )
}

// A parent's view of one child, or a teacher's / admin's view of a student (staff can delete notes).
export default function StudentBehaviorPage({ staff = false }) {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const child = (user?.children ?? []).find((c) => String(c.id) === id)
  const name = child ? child[`name_${i18n.language}`] || child.name_ar : ''
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{staff ? t('behavior.title') : t('behavior.childTitle', { name })}</h1>
      <StudentBehavior studentId={id} canEdit={staff} />
    </section>
  )
}
