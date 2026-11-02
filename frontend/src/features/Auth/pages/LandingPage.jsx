import { Link, Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../../../components/shared/LanguageSwitcher'
import SwitchSchoolButton from '../../../components/shared/SwitchSchoolButton'
import { homeFor } from '../../../app/guards'
import { useSchool } from '../../../tenant/useSchool'
import { useAuth } from '../AuthProvider'
import LandingView from '../components/LandingView'

// The first page of a school's site: its logo and name, with the login button.
export default function LandingPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { data: school } = useSchool()
  const name = school?.name ?? t('common.appName')

  if (user) return <Navigate to={homeFor(user.role)} replace />

  const login = <Link to="/login" className="cta inline-block bg-brand text-white">{t('landing.login')}</Link>
  return (
    <LandingView
      name={name}
      logo={school?.logo}
      title={t('landing.heroTitle')}
      text={t('landing.heroText')}
      header={<><LanguageSwitcher /><SwitchSchoolButton /><Link to="/login" className="rounded bg-brand px-5 py-2 text-sm text-white">{t('landing.login')}</Link></>}
      actions={login}
      ctaActions={login}
    />
  )
}
