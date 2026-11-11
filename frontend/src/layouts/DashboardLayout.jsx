import { Link, Outlet, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import BackButton from '../components/shared/BackButton'
import UserMenu from '../components/shared/UserMenu'
import HeaderIcons from '../components/shared/HeaderIcons'
import { useAuth } from '../features/Auth/AuthProvider'
import { isHidden } from '../features/StaffAccess/staffFeatures'
import { useSchool } from '../tenant/useSchool'
import SchoolLogo from '../components/shared/SchoolLogo'
import BottomTabBar from '../components/shared/BottomTabBar'
import ResponsiveTables from '../components/shared/ResponsiveTables'

// Mobile-first shell shared by all four roles.
export default function DashboardLayout() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const { pathname } = useLocation()
  const { data: school } = useSchool()
  const subject = user.subject ? user.subject[`name_${i18n.language}`] || user.subject.name_ar : null
  const isHead = user.staff_type === 'head_of_department'
  // "Student: Ahmad Khaled · 5-A", "Teacher: Sara · Math", "Head of department Math: Omar", "Parent: Mohammad".
  const label = isHead ? [t('staff.types.head_of_department'), subject].filter(Boolean).join(' ') : t(`roles.${user.role}`)
  const detail = user.role === 'student' ? user.classroom : isHead ? null : subject

  return (
    <div className="has-tabbar min-h-screen">
      <header className="sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-fog bg-snow px-4 py-2.5 print:hidden">
        <Link to={`/${user.role}`} className="flex min-w-0 items-center gap-2">
          <SchoolLogo url={school?.logo_url} className="h-9 w-9" />
          <span className="truncate text-lg font-extrabold text-deep-harbor">{school?.name ?? t('common.appName')}</span>
        </Link>
        <div className="flex items-center gap-1.5 sm:gap-2">
          <HeaderIcons />
          <UserMenu detail={[label, detail].filter(Boolean).join(' · ')} />
        </div>
      </header>
      <main id="main" tabIndex={-1} className="mx-auto max-w-5xl p-4 outline-none">
        <BackButton />
        {isHidden(user, pathname) ? <p role="status" className="rounded-lg border bg-snow p-6 text-center text-slate-600">{t('staffAccess.notAvailable')}</p> : <Outlet />}
      </main>
      <BottomTabBar />
      <ResponsiveTables />
    </div>
  )
}
