import { useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { saveBlob } from '../../api/download'
import { company } from '../../config/company'
import AuthShell from '../../components/shared/AuthShell'
import { useSchool } from '../../tenant/useSchool'
import { formatWhen } from '../SuperAdmin/format'
import { homeFor } from '../../app/guards'
import { useAuth } from './AuthProvider'

// The only page an administrator of a school in its exit window sees: the countdown and the data package. A bare page on purpose:
// no layout and no queries of the app, because every other call of the school is blocked.
export default function DownloadYourDataPage() {
  const { t, i18n } = useTranslation()
  const { user, logout } = useAuth()
  const { data: school, isLoading: schoolLoading } = useSchool()
  const [failed, setFailed] = useState(false)
  const [busy, setBusy] = useState(false)
  const exit = useQuery({
    queryKey: ['exit-export'],
    queryFn: () => api.get('/exit-export').then((r) => r.data),
    refetchInterval: 60000,
    retry: false,
  })

  // The school is not leaving (or the window has ended): there is nothing to download here.
  if (!schoolLoading && school && !school.exit_window_ends_at) return <Navigate to={homeFor(user.role)} replace />

  const left = exit.data?.seconds_left ?? 0
  const days = Math.floor(left / 86400)
  const hours = Math.floor((left % 86400) / 3600)

  const download = async () => {
    setFailed(false)
    setBusy(true)
    try {
      saveBlob((await api.get('/exit-export/package', { responseType: 'blob' })).data, `${school?.name ?? 'school'}-data.zip`)
    } catch {
      setFailed(true)
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthShell name={school?.name} logo={school?.logo_url} width="max-w-md">
      <section className="auth-card space-y-4 break-words">
        <h1 className="text-2xl font-extrabold">{t('exit.title')}</h1>
        <p className="text-slate-600">{t('exit.intro')}</p>
        {exit.isLoading && <p className="text-slate-500">{t('common.loading')}</p>}
        {exit.isError && <p role="alert" className="text-red-700">{t('exit.loadFailed')}</p>}
        {exit.data && (
          <>
            <div className="rounded-lg bg-sky-tint p-4 text-center">
              <p className="text-sm text-slate-600">{t('exit.timeLeft')}</p>
              <p className="text-2xl font-extrabold text-deep-harbor">
                {left < 3600 ? t('exit.lessThanHour') : `${t('exit.days', { count: days })} ${t('exit.hours', { count: hours })}`}
              </p>
              <p className="mt-1 text-sm text-slate-600">{t('exit.endsOn', { date: formatWhen(exit.data.ends_at, i18n.language) })}</p>
            </div>
            {!exit.data.ready && <p role="status" className="text-slate-600">{t('exit.preparing')}</p>}
            {failed && <p role="alert" className="text-red-700">{t('exit.downloadFailed')}</p>}
            <button type="button" disabled={!exit.data.ready || busy} onClick={download} className="cta block w-full bg-brand text-center text-white disabled:opacity-60">{t('exit.download')}</button>
          </>
        )}
        {(company.email || company.phone) && (
          <p className="text-sm text-slate-600">
            {t('exit.contact')}{' '}
            {company.email && <a className="font-semibold" dir="ltr" href={`mailto:${company.email}`}>{company.email}</a>}
            {company.email && company.phone && ' · '}
            {company.phone && <span dir="ltr">{company.phone}</span>}
          </p>
        )}
        <button type="button" onClick={logout} className="w-full text-center text-sm font-semibold">{t('exit.signOut')}</button>
      </section>
    </AuthShell>
  )
}
