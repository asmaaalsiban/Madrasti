import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../../components/shared/LanguageSwitcher'
import LandingView from '../Auth/components/LandingView'
import { useRegistration } from './useRegistration'

// The first page when no school is chosen yet: the platform's own welcome, with the two ways in.
export default function WelcomePage() {
  const { t } = useTranslation()
  const { enabled: canRegister } = useRegistration()
  const actions = (
    <>
      {canRegister && <Link to="/register" className="cta inline-block bg-brand text-white">{t('central.createAccount')}</Link>}
      <Link to="/find-school" className={`cta inline-block ${canRegister ? 'border-2 border-brand bg-snow text-brand' : 'bg-brand text-white'}`}>{t('central.signIn')}</Link>
    </>
  )
  return (
    <LandingView
      name={t('common.appName')}
      logo={null}
      title={t('central.heroTitle')}
      text={t(canRegister ? 'central.heroText' : 'central.heroTextClosed')}
      header={<><LanguageSwitcher /><Link to="/find-school" className="rounded bg-brand px-5 py-2 text-sm text-white">{t('central.signIn')}</Link></>}
      actions={actions}
      ctaActions={actions}
    />
  )
}
