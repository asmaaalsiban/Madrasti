import { useEffect, useState } from 'react'
import { Suspense } from 'react'
import PageFallback from '../../components/shared/PageFallback'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../../components/shared/LanguageSwitcher'
import ResponsiveTables from '../../components/shared/ResponsiveTables'
import SuperAdminLoginPage from './SuperAdminLoginPage'
import saApi, { getSaToken, setSaToken } from './saApi'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

// The frame of the Super Admin area: without a token every page is the sign-in; with one, one top bar and the page.
export default function SuperAdminLayout() {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [signedIn, setSignedIn] = useState(() => Boolean(getSaToken()))

  useEffect(() => {
    const expired = () => { qc.clear(); setSignedIn(false) }
    window.addEventListener('sa:expired', expired)
    return () => window.removeEventListener('sa:expired', expired)
  }, [qc])

  const logout = async () => {
    try { await saApi.post('/auth/logout') } catch { /* the token may already be invalid */ }
    setSaToken(null)
    qc.clear()
    setSignedIn(false)
  }

  if (!signedIn) return <SuperAdminLoginPage onSignedIn={() => setSignedIn(true)} />

  return (
    <div className="min-h-screen bg-slate-50">
      <ResponsiveTables />
      <header className="sticky top-0 z-30 bg-deep-harbor text-white print:hidden">
        <div className="flex items-center justify-between gap-2 px-4 py-3 lg:px-8">
          <div className="flex min-w-0 items-center gap-4">
            <Link to="/super-admin/schools" className="min-w-0 truncate text-lg font-extrabold max-sm:hidden">{t('superAdmin.title')}</Link>
            <nav aria-label={t('superAdmin.title')} className="flex items-center gap-1 text-sm font-semibold">
              {[['schools', 'schoolsLink'], ['plans', 'plansLink']].map(([path, key]) => (
                <NavLink key={path} to={`/super-admin/${path}`} className={({ isActive }) => `rounded-full px-3 py-1 ${isActive ? 'bg-white/20' : 'text-white/80'}`}>{t(`superAdmin.${key}`)}</NavLink>
              ))}
            </nav>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <span className="rounded-full bg-white/90 p-1 text-deep-harbor"><LanguageSwitcher /></span>
            <InteractiveHoverButton variant="outline" type="button" className="border-white/40 px-3 py-1 text-sm" onClick={logout}>{t('common.logout')}</InteractiveHoverButton>
          </div>
        </div>
      </header>
      <main id="main" tabIndex={-1} className="px-4 py-6 outline-none lg:px-8"><Suspense fallback={<PageFallback />}><Outlet /></Suspense></main>
    </div>
  )
}
