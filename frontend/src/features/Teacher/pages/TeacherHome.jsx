import useBadges from '../../Badges/useBadges'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Award, Banknote, Bus, BookOpenCheck, CalendarClock, CircleCheck, ClipboardList, FileBarChart, FileCheck2, FileText, Fingerprint, GraduationCap, ListChecks, ListTodo, Landmark, NotebookPen, PenLine, RefreshCw, ShieldCheck, ShoppingCart, Smile, Users, Vote, Wallet, TriangleAlert } from 'lucide-react'
import { useAuth } from '../../Auth/AuthProvider'
import CheckInCard from '../../StaffAttendance/CheckInCard'
import { isHidden } from '../../StaffAccess/staffFeatures'
import { ArrangeBar, ColorButton, MoveButtons } from '../../../components/shared/Arrange'
import useLayout from '../../Layout/useLayout'
import { iconPaint, iconSize, orderBy, swap } from '../../Layout/arrange'

// [path, title key, hint key, icon]
const TEACHING = [
  ['/teacher/timetable', 'timetable.myTimetable', 'timetable.myHint', CalendarClock],
  ['/teacher/attendance', 'attendance.takeTitle', 'attendance.takeHint', CircleCheck],
  ['/teacher/exams', 'exams.myTitle', 'exams.tile', FileCheck2],
  ['/teacher/lesson-plans', 'lessonPlans.title', 'lessonPlans.hint', NotebookPen],
  ['/teacher/daily-summary', 'dailySummary.title', 'dailySummary.hint', FileText],
  ['/teacher/homework', 'homework.title', 'homework.hint', BookOpenCheck],
  ['/teacher/question-bank', 'questionBank.title', 'questionBank.hint', ListChecks],
  ['/teacher/grades', 'grading.title', 'grading.hint', ClipboardList],
]
const FOLLOW_UP = [
  ['/teacher/behavior', 'behavior.title', 'behavior.hint', Smile],
  ['/teacher/warnings', 'warnings.mineTitle', 'warnings.hint', TriangleAlert],
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

// One group of tiles. While arranging, the group has up/down buttons and each tile has earlier/later buttons.
function Section({ id, title, tiles, t, user, editing, first, last, onMoveGroup, onMoveTile, size, colors, onColor }) {
  const { box, icon: iconPx } = iconSize(size, 'card')
  const shown = tiles.filter(([to]) => !isHidden(user, to))
  if (shown.length === 0) return null
  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between gap-2">
        {title && <h2 className="text-sm font-bold uppercase tracking-wide text-slate-500">{title}</h2>}
        {editing && <MoveButtons vertical label={title} canEarlier={!first} canLater={!last} onEarlier={() => onMoveGroup(id, -1)} onLater={() => onMoveGroup(id, 1)} />}
      </div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {shown.map(([to, name, hint, Icon], i) => {
          const Wrapper = editing ? 'div' : Link
          return (
            <Wrapper key={to} {...(editing ? {} : { to })} className={`feature-card flex items-start gap-4 ${editing ? 'outline-dashed outline-2 outline-white/70' : ''}`}>
              <span aria-hidden style={{ width: box, height: box, ...iconPaint(colors[to], true) }} className="grid shrink-0 place-items-center rounded-2xl bg-white/15"><Icon size={iconPx} /></span>
              <span className="min-w-0 flex-1">
                <span className="title block">{t(name)}</span>
                <span className="hint block">{t(hint)}</span>
                {editing && (
                  <span className="mt-2 flex items-center gap-1">
                    <MoveButtons label={t(name)} canEarlier={i > 0} canLater={i < shown.length - 1} onEarlier={() => onMoveTile(to, -1)} onLater={() => onMoveTile(to, 1)} />
                    <ColorButton label={t(name)} value={colors[to]} onChange={(c) => onColor(to, c)} />
                  </span>
                )}
              </span>
            </Wrapper>
          )
        })}
      </div>
    </section>
  )
}

// The teacher's start page: the day first (arrive, today's lessons), then the tools in groups. An accountant's home is the school's money.
export default function TeacherHome() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const pending = { data: useBadges().surveys }
  const accountant = user?.staff_type === 'accountant'
  const head = user?.staff_type === 'head_of_department'
  const layout = useLayout('teacher_home')
  const [editing, setEditing] = useState(false)
  // The groups of this person's home (an accountant's home is the school's money), in the order they chose.
  // A driver or an escort also has "My bus" (his bus, rounds and students); a driver has no lessons, so his home is only his own things.
  const busTile = (user?.bus_duty?.length ?? 0) > 0 ? [['/teacher/my-bus', 'transport.myBusTitle', 'transport.myBusTile', Bus]] : []
  const driver = user?.staff_type === 'driver'
  const base = driver ? [] : accountant ? [['money', MONEY]] : [['teaching', TEACHING], ['followUp', FOLLOW_UP], ...(head ? [['department', HEAD]] : [])]
  const sections = orderBy([...base, ['mine', [...busTile, ...MINE]]], (x) => x[0], layout.groups)
  const flat = () => sections.flatMap(([, tiles]) => orderBy(tiles, (x) => x[0], layout.items).map((x) => x[0]))
  const setColor = (key, color) => { const colors = { ...layout.colors }; if (color) colors[key] = color; else delete colors[key]; layout.save({ colors }) }
  const moveGroup = (id, delta) => layout.save({ groups: swap(sections.map((x) => x[0]), id, delta), items: flat() })
  const moveTile = (path, delta) => {
    const section = sections.find(([, tiles]) => tiles.some((x) => x[0] === path))
    const within = swap(orderBy(section[1], (x) => x[0], layout.items).map((x) => x[0]), path, delta)
    layout.save({ groups: sections.map((x) => x[0]), items: [...flat().filter((k) => !within.includes(k)), ...within] })
  }
  return (
    <div className="space-y-6">
      <h1>{t('dashboard.welcome', { name: user?.name })}</h1>
      <CheckInCard />
      {pending.data > 0 && (
        <Link to="/teacher/my-surveys" className="block rounded-lg border border-brand bg-blue-50 p-4 font-medium text-brand">{t('surveys.waiting', { count: pending.data })}</Link>
      )}
      <ArrangeBar editing={editing} onToggle={() => setEditing(!editing)} onReset={layout.reset} custom={layout.custom} size={layout.size} onSize={(size) => layout.save({ size })} />
      {sections.map(([id, tiles], i) => (
        <Section key={id} id={id} title={t(`teacherHome.${id}`)} tiles={orderBy(tiles, (x) => x[0], layout.items)} t={t} user={user} editing={editing}
          first={i === 0} last={i === sections.length - 1} onMoveGroup={moveGroup} onMoveTile={moveTile} size={layout.size} colors={layout.colors} onColor={setColor} />
      ))}
    </div>
  )
}
