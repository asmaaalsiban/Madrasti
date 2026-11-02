import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../Auth/AuthProvider'
import StudentGrades from '../../Grading/components/StudentGrades'

// The student's start page: their published grades.
export default function StudentHome() {
  const { t } = useTranslation()
  const { user } = useAuth()
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('studentGrades.myGrades')}</h1>
        <div className="flex flex-wrap gap-2">
          <Link to="/student/report-card" className="rounded border px-3 py-1.5 text-sm text-brand">{t('reportCard.title')}</Link>
          <Link to="/student/homework" className="rounded border px-3 py-1.5 text-sm text-brand">{t('homework.title')}</Link>
          <Link to="/student/lesson-plans" className="rounded border px-3 py-1.5 text-sm text-brand">{t('lessonPlans.title')}</Link>
          <Link to="/student/summaries" className="rounded border px-3 py-1.5 text-sm text-brand">{t('dailySummary.title')}</Link>
          <Link to="/student/behavior" className="rounded border px-3 py-1.5 text-sm text-brand">{t('behavior.title')}</Link>
          <Link to="/student/motivation" className="rounded border px-3 py-1.5 text-sm text-brand">{t('motivation.title')}</Link>
          <Link to="/student/timetable" className="rounded border px-3 py-1.5 text-sm text-brand">{t('timetable.myTimetable')}</Link>
        </div>
      </div>
      {user?.student_id ? <StudentGrades studentId={user.student_id} /> : <p className="text-slate-600">{t('studentGrades.noRecord')}</p>}
    </section>
  )
}
