import { useState } from 'react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import BackButton from '../components/shared/BackButton'
import LanguageSwitcher from '../components/shared/LanguageSwitcher'
import SwitchSchoolButton from '../components/shared/SwitchSchoolButton'
import { useAuth } from '../features/Auth/AuthProvider'
import { useSchool } from '../tenant/useSchool'

const NAV = [
  ['/admin', 'overview', true],
  ['/admin/academic-years', 'academicYears'],
  ['/admin/grade-levels', 'gradeLevels'],
  ['/admin/classrooms', 'classrooms'],
  ['/admin/subjects', 'subjects'],
  ['/admin/terms', 'terms'],
  ['/admin/teaching', 'teaching'],
  ['/admin/staff', 'staff'],
  ['/admin/students', 'students'],
  ['/admin/attendance', 'attendance'],
  ['/admin/attendance-report', 'attendanceReport'],
]

export default function AdminLayout() {
  const { t } = useTranslation()
  const { logout } = useAuth()
  const { data: school } = useSchool()
  const [open, setOpen] = useState(false)

  return (
    <div className="min-h-screen md:flex">
      <aside className="bg-brand text-white md:min-h-screen md:w-60">
        <div className="flex items-center justify-between p-4">
          <span className="font-bold">{school?.name ?? t('common.appName')}</span>
          <button
            type="button"
            className="rounded border px-3 py-1 text-sm md:hidden"
            aria-expanded={open}
            onClick={() => setOpen((v) => !v)}
          >
            {t('admin.menu')}
          </button>
        </div>
        <nav className={`${open ? 'block' : 'hidden'} space-y-1 px-2 pb-4 md:block`}>
          {NAV.map(([to, key, end]) => (
            <NavLink
              key={to}
              to={to}
              end={end}
              onClick={() => setOpen(false)}
              className={({ isActive }) => `block rounded px-3 py-2 ${isActive ? 'bg-white/20' : 'hover:bg-white/10'}`}
            >
              {t(`admin.nav.${key}`)}
            </NavLink>
          ))}
        </nav>
      </aside>
      <div className="flex-1">
        <header className="flex items-center justify-end gap-2 border-b bg-white px-4 py-3">
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
