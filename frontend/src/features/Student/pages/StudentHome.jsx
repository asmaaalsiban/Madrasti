import { useTranslation } from 'react-i18next'
import { useAuth } from '../../Auth/AuthProvider'
import StudentGrades from '../../Grading/components/StudentGrades'

// The student's start page: their published grades.
export default function StudentHome() {
  const { t } = useTranslation()
  const { user } = useAuth()
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('studentGrades.myGrades')}</h1>
      {user?.student_id ? <StudentGrades studentId={user.student_id} /> : <p className="text-slate-600">{t('studentGrades.noRecord')}</p>}
    </section>
  )
}
