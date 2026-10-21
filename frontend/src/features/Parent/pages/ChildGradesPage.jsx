import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../Auth/AuthProvider'
import StudentGrades from '../../Grading/components/StudentGrades'

// The published grades of one child of the signed-in parent.
export default function ChildGradesPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const child = (user?.children ?? []).find((c) => String(c.id) === id)
  const name = child ? child[`name_${i18n.language}`] || child.name_ar : ''
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('studentGrades.childTitle', { name })}</h1>
      <StudentGrades studentId={id} />
    </section>
  )
}
