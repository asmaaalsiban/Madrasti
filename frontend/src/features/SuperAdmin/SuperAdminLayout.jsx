import { useEffect, useState } from 'react'
import { Suspense } from 'react'
import PageFallback from '../../components/shared/PageFallback'
import { Link, Outlet } from 'react-router-dom'
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
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-2 px-4 py-3">
          <Link to="/super-admin/schools" className="min-w-0 truncate text-lg font-extrabold">{t('superAdmin.title')}</Link>
          <div className="flex shrink-0 items-center gap-2">
            <span className="rounded-full bg-white/90 p-1 text-deep-harbor"><LanguageSwitcher /></span>
            <InteractiveHoverButton variant="outline" type="button" className="border-white/40 px-3 py-1 text-sm" onClick={logout}>{t('common.logout')}</InteractiveHoverButton>
          </div>
        </div>
      </header>
      <main id="main" tabIndex={-1} className="mx-auto max-w-6xl px-4 py-6 outline-none"><Suspense fallback={<PageFallback />}><Outlet /></Suspense></main>
    </div>
  )
}
