import { Link, Outlet } from 'react-router-dom'
import { Suspense } from 'react'
import PageFallback from '../../components/shared/PageFallback'
import { useTranslation } from 'react-i18next'
import { company } from '../../config/company'
import PwaBar from '../../pwa/PwaBar'

// Wraps every page: a "skip to the content" link for keyboards (the first thing a Tab reaches), the page itself, then a slim bar with the
// legal links so they are one click away everywhere (also before signing in).
export default function LegalShell() {
  const { t } = useTranslation()
  return (
    <>
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:start-2 focus:top-2 focus:z-50 focus:rounded focus:bg-deep-harbor focus:px-4 focus:py-2 focus:text-white" onClick={() => document.getElementById('main')?.focus()}>{t('a11y.skip')}</a>
      <Suspense fallback={<PageFallback />}><Outlet /></Suspense>
      <PwaBar />
      <div className="border-t border-fog bg-snow px-4 py-3 text-center text-xs text-slate-600 print:hidden">
        <nav aria-label={t('legal.docs')} className="flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
          <Link to="/privacy" className="underline">{t('legal.privacy.title')}</Link>
          <Link to="/terms" className="underline">{t('legal.terms.title')}</Link>
          <Link to="/cookies" className="underline">{t('legal.cookies.title')}</Link>
          <span>© {new Date().getFullYear()} {company.legalName || company.name}</span>
        </nav>
      </div>
    </>
  )
}
