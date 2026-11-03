import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Award, Banknote, BookOpenCheck, CalendarClock, CircleCheck, ClipboardList, FileBarChart, FileCheck2, Images, FileText, Fingerprint, GraduationCap, ListChecks, ListTodo, Landmark, NotebookPen, PenLine, RefreshCw, ShieldCheck, ShoppingCart, Smile, Users, Vote, Wallet } from 'lucide-react'
import { api } from '../../../api/client'
import { useAuth } from '../../Auth/AuthProvider'
import CheckInCard from '../../StaffAttendance/CheckInCard'
import { isHidden } from '../../StaffAccess/staffFeatures'

// [path, title key, hint key, icon]
const TEACHING = [
  ['/teacher/stories', 'stories.title', 'stories.tile', Images],
  ['/teacher/timetable', 'timetable.myTimetable', 'timetable.myHint', CalendarClock],
  ['/teacher/attendance', 'attendance.takeTitle', 'attendance.takeHint', CircleCheck],
  ['/teacher/exams', 'exams.myTitle', 'exams.tile', FileCheck2],
  ['/teacher/lesson-plans', 'lessonPlans.title', 'lessonPlans.hint', NotebookPen],
  ['/teacher/daily-summary', 'dailySummary.title', 'dailySummary.hint', FileText],
  ['/teacher/homework', 'homework.title', 'homework.hint', BookOpenCheck],
  ['/teacher/grades', 'grading.title', 'grading.hint', ClipboardList],
]
const FOLLOW_UP = [
  ['/teacher/behavior', 'behavior.title', 'behavior.hint', Smile],
  ['/teacher/motivation', 'motivation.title', 'motivation.hint', Award],
  ['/teacher/surveys', 'surveys.title', 'surveys.hint', Vote],
  ['/teacher/signature', 'signatures.mineTitle', 'signatures.mineHint', PenLine],
  ['/teacher/attendance/report', 'attendance.reportTitle', 'attendance.reportHint', FileBarChart],
]
const HEAD = [
  ['/teacher/lesson-plans/review', 'lessonPlans.reviewTitle', 'lessonPlans.reviewHint', ListChecks],
  ['/teacher/department', 'department.cardTitle', 'department.cardHint', Users],
]
const MONEY = [
  ['/teacher/finance', 'finance.title', 'finance.hint', Wallet],
  ['/teacher/finance/accounts', 'finance.accounts', 'finance.accountsHint', Landmark],
  ['/teacher/finance/structures', 'finance.structures', 'finance.structuresHint', ClipboardList],
  ['/teacher/renewals', 'renewals.title', 'renewals.hint', RefreshCw],
  ['/teacher/payroll', 'payroll.title', 'payroll.hint', Banknote],
  ['/teacher/payroll/contracts', 'payroll.contractsTitle', 'payroll.contractsHint', GraduationCap],
]
const MINE = [
  ['/teacher/tasks', 'tasks.title', 'tasks.tile', ListTodo],
  ['/teacher/procurement', 'procurement.title', 'procurement.tile', ShoppingCart],
  ['/teacher/duties', 'duties.myTitle', 'duties.tile', ShieldCheck],
  ['/teacher/staff-attendance', 'staffAttendance.title', 'staffAttendance.hint', Fingerprint],
  ['/teacher/payslips', 'payroll.mineTitle', 'payroll.mineHint', Banknote],
]

function Section({ title, tiles, t, user }) {
  const shown = tiles.filter(([to]) => !isHidden(user, to))
  if (shown.length === 0) return null
  return (
    <section className="space-y-3">
      {title && <h2 className="text-sm font-bold uppercase tracking-wide text-slate-500">{title}</h2>}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {shown.map(([to, name, hint, Icon]) => (
          <Link key={to} to={to} className="feature-card flex items-start gap-4">
            <span aria-hidden className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-white/15"><Icon size={22} /></span>
            <span className="min-w-0">
              <span className="title block">{t(name)}</span>
              <span className="hint block">{t(hint)}</span>
            </span>
          </Link>
        ))}
      </div>
    </section>
  )
}

// The teacher's start page: the day first (arrive, today's lessons), then the tools in groups. An accountant's home is the school's money.
export default function TeacherHome() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const pending = useQuery({ queryKey: ['my-surveys', 'pending'], queryFn: async () => (await api.get('/my-surveys/pending')).data.data.count, refetchInterval: 60000 })
  const accountant = user?.staff_type === 'accountant'
  const head = user?.staff_type === 'head_of_department'
  return (
    <div className="space-y-6">
      <h1>{t('dashboard.welcome', { name: user?.name })}</h1>
      <CheckInCard />
      {pending.data > 0 && (
        <Link to="/teacher/my-surveys" className="block rounded-lg border border-brand bg-blue-50 p-4 font-medium text-brand">{t('surveys.waiting', { count: pending.data })}</Link>
      )}
      {accountant ? (
        <Section title={t('teacherHome.money')} tiles={MONEY} t={t} user={user} />
      ) : (
        <>
          <Section title={t('teacherHome.teaching')} tiles={TEACHING} t={t} user={user} />
          <Section title={t('teacherHome.followUp')} tiles={FOLLOW_UP} t={t} user={user} />
          {head && <Section title={t('teacherHome.department')} tiles={HEAD} t={t} user={user} />}
        </>
      )}
      <Section title={t('teacherHome.mine')} tiles={MINE} t={t} user={user} />
    </div>
  )
}
