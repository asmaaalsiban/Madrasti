import { useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { Award, Banknote, BookOpen, Briefcase, Building2, CalendarClock, CalendarDays, CalendarRange, CircleCheck, ClipboardList, Clock, DoorOpen, FileBarChart, FileCheck2, FileUp, FolderOpen, Fingerprint, GraduationCap, LayoutDashboard, Layers, ListChecks, Menu, PenLine, RefreshCw, ScrollText, ShieldCheck, Camera, FileLock2, ListTodo, EyeOff, ShoppingCart, Smile, Bus, UserCheck, Users, Vote, Wallet } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import BackButton from '../components/shared/BackButton'
import UserMenu from '../components/shared/UserMenu'
import HeaderIcons from '../components/shared/HeaderIcons'
import { ArrangeBar, ColorButton, MoveButtons } from '../components/shared/Arrange'
import useLayout from '../features/Layout/useLayout'
import { iconPaint, iconSize, orderBy, swap } from '../features/Layout/arrange'
import { useSchool } from '../tenant/useSchool'

// The menu in groups, each item with its icon: what the principal reaches for most is at the top.
const GROUPS = [
  { key: null, items: [['/admin', 'overview', LayoutDashboard, true]] },
  { key: 'people', items: [['/admin/students', 'students', GraduationCap], ['/admin/student-files', 'studentFiles', FolderOpen], ['/admin/renewals', 'renewals', RefreshCw], ['/admin/staff', 'staff', Briefcase], ['/admin/tasks', 'tasks', ListTodo], ['/admin/staff-access', 'staffAccess', EyeOff], ['/admin/import', 'import', FileUp]] },
  { key: 'learning', items: [['/admin/attendance', 'attendance', CircleCheck], ['/admin/attendance-report', 'attendanceReport', FileBarChart], ['/admin/grades', 'grades', ClipboardList], ['/admin/behavior', 'behavior', Smile], ['/admin/motivation', 'motivation', Award], ['/admin/surveys', 'surveys', Vote], ['/admin/signatures', 'signatures', PenLine]] },
  { key: 'schedule', items: [['/admin/timetable', 'timetable', CalendarClock], ['/admin/exams', 'exams', FileCheck2], ['/admin/duties', 'duties', ShieldCheck], ['/admin/school-day', 'schoolDay', Clock], ['/admin/transport', 'transport', Bus], ['/admin/availability', 'availability', UserCheck], ['/admin/teaching', 'teaching', Users]] },
  { key: 'money', items: [['/admin/finance', 'finance', Wallet], ['/admin/payroll', 'payroll', Banknote], ['/admin/staff-attendance', 'staffAttendance', Fingerprint], ['/admin/procurement', 'procurement', ShoppingCart]] },
  { key: 'school', items: [['/admin/academic-years', 'academicYears', CalendarRange], ['/admin/terms', 'terms', CalendarDays], ['/admin/grade-levels', 'gradeLevels', Layers], ['/admin/classrooms', 'classrooms', DoorOpen], ['/admin/subjects', 'subjects', BookOpen], ['/admin/curriculum', 'curriculum', ListChecks], ['/admin/buildings', 'buildings', Building2]] },
  { key: 'system', items: [['/admin/audit-log', 'auditLog', ScrollText], ['/admin/photo-consent', 'photoConsent', Camera], ['/admin/privacy-requests', 'privacyRequests', FileLock2]] },
]

export default function AdminLayout() {
  const { t } = useTranslation()
  const { data: school } = useSchool()
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState(false)
  const layout = useLayout('admin_nav')
  // The principal's own order: the top entry stays first; the groups and the entries inside them follow what they arranged.
  const groups = [GROUPS[0], ...orderBy(GROUPS.slice(1), (g) => g.key, layout.groups)].map((g) => ({ ...g, items: g.key === null ? g.items : orderBy(g.items, (i) => i[0], layout.items) }))
  const groupKeys = groups.filter((g) => g.key).map((g) => g.key)
  const flat = () => groups.flatMap((g) => g.items.map((i) => i[0]))
  const { box, icon: iconPx } = iconSize(layout.size, 'menu')
  const setColor = (key, color) => { const colors = { ...layout.colors }; if (color) colors[key] = color; else delete colors[key]; layout.save({ colors }) }
  const iconOf = (Icon, to) => <span aria-hidden style={{ width: layout.colors[to] ? box : iconPx, height: layout.colors[to] ? box : iconPx, ...iconPaint(layout.colors[to], true) }} className="grid shrink-0 place-items-center rounded-full"><Icon size={iconPx} /></span>
  const moveGroup = (key, delta) => layout.save({ groups: swap(groupKeys, key, delta), items: flat() })
  const moveItem = (group, path, delta) => {
    const within = swap(group.items.map((i) => i[0]), path, delta)
    layout.save({ groups: groupKeys, items: [...flat().filter((k) => !within.includes(k)), ...within] })
  }

  return (
    <div className="min-h-screen md:flex">
      <aside className="bg-deep-harbor text-white md:sticky md:top-0 md:h-screen md:w-64 md:shrink-0 md:overflow-y-auto print:hidden">
        <div className="flex items-center justify-between gap-2 p-4">
          <span className="flex min-w-0 items-center gap-2">
            {school?.logo && <img src={school.logo} alt="" className="h-9 w-9 rounded-xl object-cover" />}
            <span className="truncate text-lg font-extrabold">{school?.name ?? t('common.appName')}</span>
          </span>
          <button type="button" className="flex items-center gap-1 rounded border px-3 py-1 text-sm md:hidden" aria-expanded={open} onClick={() => setOpen((v) => !v)}>
            <Menu aria-hidden size={16} />{t('admin.menu')}
          </button>
        </div>
        <nav className={`${open ? 'block' : 'hidden'} space-y-4 px-3 pb-6 md:block`}>
          <div className="px-1"><ArrangeBar className="[&_button]:!border-white/30 [&_span]:!text-white/70" editing={editing} onToggle={() => setEditing(!editing)} onReset={layout.reset} custom={layout.custom} size={layout.size} onSize={(size) => layout.save({ size })} /></div>
          {groups.map((group, gi) => (
            <div key={group.key ?? 'top'} className="space-y-0.5">
              {group.key && (
                <p className="flex items-center justify-between px-3 pb-1 pt-2 text-xs font-bold uppercase tracking-wide text-white/80">
                  {t(`admin.groups.${group.key}`)}
                  {editing && <MoveButtons vertical label={t(`admin.groups.${group.key}`)} canEarlier={gi > 1} canLater={gi < groups.length - 1} onEarlier={() => moveGroup(group.key, -1)} onLater={() => moveGroup(group.key, 1)} />}
                </p>
              )}
              {group.items.map(([to, key, Icon, end], ii) => editing ? (
                <div key={to} className="flex items-center gap-3 rounded-full border border-dashed border-white/30 px-3 py-1.5 text-sm text-white/90">
                  {iconOf(Icon, to)}
                  <span className="min-w-0 flex-1 truncate">{t(`admin.nav.${key}`)}</span>
                  <ColorButton label={t(`admin.nav.${key}`)} value={layout.colors[to]} onChange={(c) => setColor(to, c)} />
                  {group.key && <MoveButtons vertical label={t(`admin.nav.${key}`)} canEarlier={ii > 0} canLater={ii < group.items.length - 1} onEarlier={() => moveItem(group, to, -1)} onLater={() => moveItem(group, to, 1)} />}
                </div>
              ) : (
                <NavLink
                  key={to}
                  to={to}
                  end={end}
                  onClick={() => setOpen(false)}
                  className={({ isActive }) => `flex items-center gap-3 rounded-full px-3 py-2 text-sm ${isActive ? 'bg-sky-tint font-bold text-deep-harbor' : 'text-white/90 hover:bg-white/10'}`}
                >
                  {iconOf(Icon, to)}
                  <span className="min-w-0 flex-1 truncate">{t(`admin.nav.${key}`)}</span>
                </NavLink>
              ))}
            </div>
          ))}
        </nav>
      </aside>
      <div className="min-w-0 flex-1">
        <header className="sticky top-0 z-30 flex items-center justify-end gap-2 border-b border-fog bg-snow px-4 py-2.5 print:hidden">
          <div className="flex items-center gap-1.5 sm:gap-2">
            <HeaderIcons />
            <UserMenu detail={t('roles.admin')} />
          </div>
        </header>
        <main id="main" tabIndex={-1} className="mx-auto max-w-5xl p-4 outline-none"><BackButton /><Outlet /></main>
      </div>
    </div>
  )
}
