import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useAuth } from '../../Auth/AuthProvider'
import StudentGrades from '../../Grading/components/StudentGrades'

// The student's start page: their published grades.
export default function StudentHome() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const pending = useQuery({ queryKey: ['my-surveys', 'pending'], queryFn: async () => (await api.get('/my-surveys/pending')).data.data.count, refetchInterval: 60000, enabled: Boolean(user?.student_id) })
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('studentGrades.myGrades')}</h1>
        <div className="flex flex-wrap gap-2">
          <Link to="/student/report-card" className="rounded border px-3 py-1.5 text-sm text-brand">{t('reportCard.title')}</Link>
          <Link to="/student/surveys" className="rounded border px-3 py-1.5 text-sm text-brand">{t('surveys.mine')}{pending.data > 0 && <span className="ms-1 rounded-full bg-brand px-2 text-xs text-white">{pending.data}</span>}</Link>
          <Link to="/student/homework" className="rounded border px-3 py-1.5 text-sm text-brand">{t('homework.title')}</Link>
          <Link to="/student/lesson-plans" className="rounded border px-3 py-1.5 text-sm text-brand">{t('lessonPlans.title')}</Link>
          <Link to="/student/summaries" className="rounded border px-3 py-1.5 text-sm text-brand">{t('dailySummary.title')}</Link>
          <Link to="/student/behavior" className="rounded border px-3 py-1.5 text-sm text-brand">{t('behavior.title')}</Link>
          <Link to="/student/motivation" className="rounded border px-3 py-1.5 text-sm text-brand">{t('motivation.title')}</Link>
          <Link to="/student/timetable" className="rounded border px-3 py-1.5 text-sm text-brand">{t('timetable.myTimetable')}</Link>
        </div>
      </div>
      {pending.data > 0 && (
        <Link to="/student/surveys" className="block rounded-lg border border-brand bg-blue-50 p-4 font-medium text-brand">{t('surveys.waiting', { count: pending.data })}</Link>
      )}
      {user?.student_id ? <StudentGrades studentId={user.student_id} /> : <p className="text-slate-600">{t('studentGrades.noRecord')}</p>}
    </section>
  )
}
