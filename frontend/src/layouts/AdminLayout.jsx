import { useState } from 'react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import BackButton from '../components/shared/BackButton'
import LanguageSwitcher from '../components/shared/LanguageSwitcher'
import SwitchSchoolButton from '../components/shared/SwitchSchoolButton'
import useUnreadChat from '../features/Chat/hooks/useUnreadChat'
import useUnreadAnnouncements from '../features/Announcements/hooks/useUnreadAnnouncements'
import { useAuth } from '../features/Auth/AuthProvider'
import NotificationBell from '../features/Notifications/NotificationBell'
import { useSchool } from '../tenant/useSchool'

const NAV = [
  ['/admin', 'overview', true],
  ['/admin/academic-years', 'academicYears'],
  ['/admin/buildings', 'buildings'],
  ['/admin/grade-levels', 'gradeLevels'],
  ['/admin/classrooms', 'classrooms'],
  ['/admin/subjects', 'subjects'],
  ['/admin/curriculum', 'curriculum'],
  ['/admin/terms', 'terms'],
  ['/admin/school-day', 'schoolDay'],
  ['/admin/timetable', 'timetable'],
  ['/admin/availability', 'availability'],
  ['/admin/teaching', 'teaching'],
  ['/admin/staff', 'staff'],
  ['/admin/students', 'students'],
  ['/admin/renewals', 'renewals'],
  ['/admin/import', 'import'],
  ['/admin/grades', 'grades'],
  ['/admin/chat', 'chat'],
  ['/admin/finance', 'finance'],
  ['/admin/payroll', 'payroll'],
  ['/admin/staff-attendance', 'staffAttendance'],
  ['/admin/audit-log', 'auditLog'],
  ['/admin/announcements', 'announcements'],
  ['/admin/surveys', 'surveys'],
  ['/admin/signatures', 'signatures'],
  ['/admin/behavior', 'behavior'],
  ['/admin/motivation', 'motivation'],
  ['/admin/attendance', 'attendance'],
  ['/admin/attendance-report', 'attendanceReport'],
]

export default function AdminLayout() {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  const { data: school } = useSchool()
  const [open, setOpen] = useState(false)
  const unread = useUnreadAnnouncements()
  const unreadChat = useUnreadChat()

  return (
    <div className="min-h-screen md:flex">
      <aside className="bg-deep-harbor text-white md:min-h-screen md:w-60 print:hidden">
        <div className="flex items-center justify-between p-4">
          <span className="text-lg font-extrabold">{school?.name ?? t('common.appName')}</span>
          <button
            type="button"
            className="rounded border px-3 py-1 text-sm md:hidden"
            aria-expanded={open}
            onClick={() => setOpen((v) => !v)}
          >
            {t('admin.menu')}
          </button>
        </div>
        <div className="px-4 pb-2"><NotificationBell /></div>
        {user?.name && <p className="px-4 pb-2 text-sm text-white/80">{t('roles.admin')}: <strong>{user.name}</strong></p>}
        <nav className={`${open ? 'block' : 'hidden'} space-y-1 px-2 pb-4 md:block`}>
          {NAV.map(([to, key, end]) => (
            <NavLink
              key={to}
              to={to}
              end={end}
              onClick={() => setOpen(false)}
              className={({ isActive }) => `block rounded-full px-4 py-2 ${isActive ? 'bg-sky-tint font-bold text-deep-harbor' : 'hover:bg-white/10'}`}
            >
              {t(`admin.nav.${key}`)}
              {key === 'chat' && unreadChat > 0 && <span className="ms-2 rounded-full bg-brand px-2 text-xs font-bold text-white">{unreadChat}</span>}
              {key === 'announcements' && unread > 0 && <span className="ms-2 rounded-full bg-brand px-2 text-xs font-bold text-white">{unread}</span>}
            </NavLink>
          ))}
        </nav>
      </aside>
      <div className="flex-1">
        <header className="flex flex-wrap items-center justify-end gap-2 border-b border-fog bg-snow px-4 py-3 print:hidden">
          <LanguageSwitcher />
          <Link to="/change-password" className="rounded border px-3 py-1 text-sm">{t('auth.changePassword.link')}</Link>
          <SwitchSchoolButton />
          <button type="button" className="rounded border px-3 py-1 text-sm" onClick={logout}>{t('common.logout')}</button>
        </header>
        <main className="mx-auto max-w-5xl p-4"><BackButton /><Outlet /></main>
      </div>
    </div>
  )
}
