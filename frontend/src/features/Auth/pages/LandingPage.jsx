import { Link, Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../../../components/shared/LanguageSwitcher'
import SwitchSchoolButton from '../../../components/shared/SwitchSchoolButton'
import { homeFor } from '../../../app/guards'
import { useSchool } from '../../../tenant/useSchool'
import { useAuth } from '../AuthProvider'

const ROLES = ['admin', 'teacher', 'student', 'parent']
const YEAR = new Date().getFullYear()
const FEATURES = [
  ['grades', '📝'],
  ['attendance', '✅'],
  ['schedule', '🗓️'],
  ['chat', '💬'],
  ['reports', '📊'],
  ['finance', '💳'],
]

export default function LandingPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { data: school } = useSchool()
  const name = school?.name ?? t('common.appName')

  if (user) return <Navigate to={homeFor(user.role)} replace />

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-4">
        <div className="flex items-center gap-2">
          {school?.logo && <img src={school.logo} alt="" className="h-9 w-9 rounded object-cover" />}
          <span className="text-lg font-bold text-brand">{name}</span>
        </div>
        <div className="flex items-center gap-2">
          <LanguageSwitcher />
          <SwitchSchoolButton />
          <Link to="/login" className="rounded bg-brand px-4 py-1.5 text-sm text-white">
            {t('landing.login')}
          </Link>
        </div>
      </header>

      <main>
        <section className="mx-auto max-w-4xl px-4 py-16 text-center sm:py-24">
          <h1 className="text-3xl font-extrabold sm:text-5xl">{t('landing.heroTitle')}</h1>
          <p className="mx-auto mt-4 max-w-2xl text-slate-600 sm:text-lg">{t('landing.heroText')}</p>
          <Link to="/login" className="mt-8 inline-block rounded-lg bg-brand px-8 py-3 text-white shadow">
            {t('landing.login')}
          </Link>
        </section>

        <section className="mx-auto max-w-6xl px-4 py-10">
          <h2 className="mb-6 text-center text-2xl font-bold">{t('landing.rolesTitle')}</h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {ROLES.map((role) => (
              <div key={role} className="rounded-lg border-t-4 border-brand bg-white p-5 shadow-sm">
                <h3 className="font-bold">{t(`roles.${role}`)}</h3>
                <p className="mt-2 text-sm text-slate-600">{t(`landing.roles.${role}`)}</p>
              </div>
            ))}
          </div>
        </section>

        <section className="mx-auto max-w-6xl px-4 py-10">
          <h2 className="mb-6 text-center text-2xl font-bold">{t('landing.featuresTitle')}</h2>
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
            {FEATURES.map(([key, icon]) => (
              <div key={key} className="flex items-center gap-3 rounded-lg bg-white p-4 shadow-sm">
                <span aria-hidden className="text-2xl">{icon}</span>
                <span className="font-medium">{t(`landing.features.${key}`)}</span>
              </div>
            ))}
          </div>
        </section>
      </main>

      <footer className="mt-10 border-t py-6 text-center text-sm text-slate-500">
        {t('landing.footer', { year: YEAR, name })}
      </footer>
    </div>
  )
}
