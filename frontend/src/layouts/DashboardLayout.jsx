import { Link, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import BackButton from '../components/shared/BackButton'
import LanguageSwitcher from '../components/shared/LanguageSwitcher'
import SwitchSchoolButton from '../components/shared/SwitchSchoolButton'
import { useAuth } from '../features/Auth/AuthProvider'
import { useSchool } from '../tenant/useSchool'

// Mobile-first shell shared by all four roles.
export default function DashboardLayout() {
  const { t, i18n } = useTranslation()
  const { user, logout } = useAuth()
  const { data: school } = useSchool()
  const subject = user.subject ? user.subject[`name_${i18n.language}`] || user.subject.name_ar : null
  const isHead = user.staff_type === 'head_of_department'
  // "Student: Ahmad Khaled · 5-A", "Teacher: Sara · Math", "Head of department Math: Omar", "Parent: Mohammad".
  const label = isHead ? [t('staff.types.head_of_department'), subject].filter(Boolean).join(' ') : t(`roles.${user.role}`)
  const detail = user.role === 'student' ? user.classroom : isHead ? null : subject

  return (
    <div className="min-h-screen">
      <header className="flex flex-wrap items-center justify-between gap-2 bg-brand px-4 py-3 text-white">
        <span className="font-bold">{school?.name ?? t('common.appName')}</span>
        <div className="flex items-center gap-2">
          <span className="text-sm">{label}: <strong>{user.name}</strong>{detail ? ` · ${detail}` : ''}</span>
          <LanguageSwitcher />
          <Link to="/change-password" className="rounded border px-3 py-1 text-sm">{t('auth.changePassword.link')}</Link>
          <SwitchSchoolButton />
          <button type="button" className="rounded border px-3 py-1 text-sm" onClick={logout}>
            {t('common.logout')}
          </button>
        </div>
      </header>
      <main className="mx-auto max-w-5xl p-4">
        <BackButton />
        <Outlet />
      </main>
    </div>
  )
}
