import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../../components/shared/LanguageSwitcher'

export default function WelcomePage() {
  const { t } = useTranslation()
  return (
    <div className="min-h-screen bg-slate-50">
      <header className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-4">
        <span className="text-lg font-bold text-brand">{t('common.appName')}</span>
        <LanguageSwitcher />
      </header>
      <main className="mx-auto max-w-3xl px-4 py-16 text-center sm:py-28">
        <h1 className="text-3xl font-extrabold sm:text-5xl">{t('central.heroTitle')}</h1>
        <p className="mx-auto mt-4 max-w-xl text-slate-600 sm:text-lg">{t('central.heroText')}</p>
        <div className="mt-8 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center">
          <Link to="/register" className="rounded-lg bg-brand px-8 py-3 text-white shadow">{t('central.createAccount')}</Link>
          <Link to="/find-school" className="rounded-lg border border-brand bg-white px-8 py-3 text-brand">{t('central.signIn')}</Link>
        </div>
      </main>
    </div>
  )
}
