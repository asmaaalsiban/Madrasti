import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../Auth/AuthProvider'

// The teacher's start page: the tools that exist so far.
export default function TeacherHome() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const cards = [
    ['/teacher/timetable', 'timetable.myTimetable', 'timetable.myHint'],
    ['/teacher/attendance', 'attendance.takeTitle', 'attendance.takeHint'],
    ['/teacher/grades', 'grading.title', 'grading.hint'],
    ['/teacher/attendance/report', 'attendance.reportTitle', 'attendance.reportHint'],
    ...(user?.staff_type === 'head_of_department' ? [['/teacher/department', 'department.cardTitle', 'department.cardHint']] : []),
  ]
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('dashboard.welcome', { name: user?.name })}</h1>
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
