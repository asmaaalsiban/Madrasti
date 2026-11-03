import { useState } from 'react'
import { NavLink, Outlet } from 'react-router-dom'
import { Award, Banknote, BookOpen, Briefcase, Building2, CalendarClock, CalendarDays, CalendarRange, CircleCheck, ClipboardList, Clock, DoorOpen, FileBarChart, FileCheck2, FileUp, Fingerprint, GraduationCap, LayoutDashboard, Layers, ListChecks, Megaphone, Menu, MessageCircle, PenLine, RefreshCw, ScrollText, ShieldCheck, Smile, UserCheck, Users, Vote, Wallet } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import BackButton from '../components/shared/BackButton'
import UserMenu from '../components/shared/UserMenu'
import useUnreadChat from '../features/Chat/hooks/useUnreadChat'
import useUnreadAnnouncements from '../features/Announcements/hooks/useUnreadAnnouncements'
import NotificationBell from '../features/Notifications/NotificationBell'
import { useSchool } from '../tenant/useSchool'

// The menu in groups, each item with its icon: what the principal reaches for most is at the top.
const GROUPS = [
  { key: null, items: [['/admin', 'overview', LayoutDashboard, true]] },
  { key: 'people', items: [['/admin/students', 'students', GraduationCap], ['/admin/renewals', 'renewals', RefreshCw], ['/admin/staff', 'staff', Briefcase], ['/admin/import', 'import', FileUp]] },
  { key: 'learning', items: [['/admin/attendance', 'attendance', CircleCheck], ['/admin/attendance-report', 'attendanceReport', FileBarChart], ['/admin/grades', 'grades', ClipboardList], ['/admin/behavior', 'behavior', Smile], ['/admin/motivation', 'motivation', Award], ['/admin/surveys', 'surveys', Vote], ['/admin/signatures', 'signatures', PenLine]] },
  { key: 'schedule', items: [['/admin/timetable', 'timetable', CalendarClock], ['/admin/exams', 'exams', FileCheck2], ['/admin/duties', 'duties', ShieldCheck], ['/admin/school-day', 'schoolDay', Clock], ['/admin/availability', 'availability', UserCheck], ['/admin/teaching', 'teaching', Users]] },
  { key: 'money', items: [['/admin/finance', 'finance', Wallet], ['/admin/payroll', 'payroll', Banknote], ['/admin/staff-attendance', 'staffAttendance', Fingerprint]] },
  { key: 'communication', items: [['/admin/chat', 'chat', MessageCircle], ['/admin/announcements', 'announcements', Megaphone]] },
  { key: 'school', items: [['/admin/academic-years', 'academicYears', CalendarRange], ['/admin/terms', 'terms', CalendarDays], ['/admin/grade-levels', 'gradeLevels', Layers], ['/admin/classrooms', 'classrooms', DoorOpen], ['/admin/subjects', 'subjects', BookOpen], ['/admin/curriculum', 'curriculum', ListChecks], ['/admin/buildings', 'buildings', Building2]] },
  { key: 'system', items: [['/admin/audit-log', 'auditLog', ScrollText]] },
]

export default function AdminLayout() {
  const { t } = useTranslation()
  const { data: school } = useSchool()
  const [open, setOpen] = useState(false)
  const unread = useUnreadAnnouncements()
  const unreadChat = useUnreadChat()
  const badge = { chat: unreadChat, announcements: unread }

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
          {GROUPS.map((group) => (
            <div key={group.key ?? 'top'} className="space-y-0.5">
              {group.key && <p className="px-3 pb-1 pt-2 text-xs font-bold uppercase tracking-wide text-white/55">{t(`admin.groups.${group.key}`)}</p>}
              {group.items.map(([to, key, Icon, end]) => (
                <NavLink
                  key={to}
                  to={to}
                  end={end}
                  onClick={() => setOpen(false)}
                  className={({ isActive }) => `flex items-center gap-3 rounded-full px-3 py-2 text-sm ${isActive ? 'bg-sky-tint font-bold text-deep-harbor' : 'text-white/90 hover:bg-white/10'}`}
                >
                  <Icon aria-hidden size={18} className="shrink-0" />
                  <span className="min-w-0 flex-1 truncate">{t(`admin.nav.${key}`)}</span>
                  {badge[key] > 0 && <span className="rounded-full bg-brand px-2 text-xs font-bold text-white">{badge[key]}</span>}
                </NavLink>
              ))}
            </div>
          ))}
        </nav>
      </aside>
      <div className="min-w-0 flex-1">
        <header className="sticky top-0 z-30 flex items-center justify-end gap-2 border-b border-fog bg-snow px-4 py-2.5 print:hidden">
          <div className="flex items-center gap-2">
            <NotificationBell />
            <UserMenu detail={t('roles.admin')} />
          </div>
        </header>
        <main className="mx-auto max-w-5xl p-4"><BackButton /><Outlet /></main>
      </div>
    </div>
  )
}
