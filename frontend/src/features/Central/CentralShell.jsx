import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../../components/shared/LanguageSwitcher'

// Header + container shared by the pages of the central (no-school) site.
export default function CentralShell({ children }) {
  const { t } = useTranslation()
  return (
    <div className="min-h-screen bg-slate-50">
      <header className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-4">
        <Link to="/" className="text-lg font-bold text-brand">{t('common.appName')}</Link>
        <LanguageSwitcher />
      </header>
      <main id="main" tabIndex={-1} className="mx-auto max-w-md px-4 py-8 outline-none">{children}</main>
    </div>
  )
}
