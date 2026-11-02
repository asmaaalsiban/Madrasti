import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useAuth } from '../../Auth/AuthProvider'

// The teacher's start page: the tools that exist so far.
export default function TeacherHome() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const pending = useQuery({ queryKey: ['my-surveys', 'pending'], queryFn: async () => (await api.get('/my-surveys/pending')).data.data.count, refetchInterval: 60000 })
  const teaching = [
    ['/teacher/timetable', 'timetable.myTimetable', 'timetable.myHint'],
    ['/teacher/attendance', 'attendance.takeTitle', 'attendance.takeHint'],
    ['/teacher/lesson-plans', 'lessonPlans.title', 'lessonPlans.hint'],
    ['/teacher/daily-summary', 'dailySummary.title', 'dailySummary.hint'],
    ['/teacher/behavior', 'behavior.title', 'behavior.hint'],
    ['/teacher/motivation', 'motivation.title', 'motivation.hint'],
    ['/teacher/surveys', 'surveys.title', 'surveys.hint'],
    ['/teacher/signature', 'signatures.mineTitle', 'signatures.mineHint'],
    ['/teacher/homework', 'homework.title', 'homework.hint'],
    ['/teacher/grades', 'grading.title', 'grading.hint'],
    ['/teacher/attendance/report', 'attendance.reportTitle', 'attendance.reportHint'],
    ...(user?.staff_type === 'head_of_department' ? [['/teacher/lesson-plans/review', 'lessonPlans.reviewTitle', 'lessonPlans.reviewHint'], ['/teacher/department', 'department.cardTitle', 'department.cardHint']] : []),
  ]
  // An accountant is not a teacher: their home is the school's money.
  const cards = user?.staff_type === 'accountant'
    ? [['/teacher/finance', 'finance.title', 'finance.hint'], ['/teacher/finance/accounts', 'finance.accounts', 'finance.accountsHint'], ['/teacher/finance/structures', 'finance.structures', 'finance.structuresHint']]
    : teaching
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('dashboard.welcome', { name: user?.name })}</h1>
      {pending.data > 0 && (
        <Link to="/teacher/my-surveys" className="block rounded-lg border border-brand bg-blue-50 p-4 font-medium text-brand">{t('surveys.waiting', { count: pending.data })}</Link>
      )}
      <div className="grid gap-3 sm:grid-cols-2">
        {cards.map(([to, title, hint]) => (
          <Link key={to} to={to} className="block rounded-lg border bg-white p-4 shadow-sm hover:border-brand">
            <span className="block font-bold">{t(title)}</span>
            <span className="block text-sm text-slate-600">{t(hint)}</span>
          </Link>
        ))}
      </div>
    </section>
  )
}
