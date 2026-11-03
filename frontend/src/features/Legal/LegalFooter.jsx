import { Link, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { company } from '../../config/company'

// Wraps every page: the page itself, then a slim footer with the legal links, so they are one click away everywhere (also before signing in).
export default function LegalShell() {
  const { t } = useTranslation()
  return (
    <>
      <Outlet />
      <footer className="border-t border-fog bg-snow px-4 py-3 text-center text-xs text-slate-600 print:hidden">
        <nav aria-label={t('legal.docs')} className="flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
          <Link to="/privacy" className="underline">{t('legal.privacy.title')}</Link>
          <Link to="/terms" className="underline">{t('legal.terms.title')}</Link>
          <Link to="/cookies" className="underline">{t('legal.cookies.title')}</Link>
          <span>© {new Date().getFullYear()} {company.legalName || company.name}</span>
        </nav>
      </footer>
    </>
  )
}
