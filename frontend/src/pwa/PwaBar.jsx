import { useTranslation } from 'react-i18next'
import { RefreshCw, WifiOff } from 'lucide-react'
import { useOnline, useUpdate } from './pwa'

// Two small notices at the bottom of the screen: "no connection" (what you see may be old and nothing is sent) and "a new version is ready".
export default function PwaBar() {
  const { t } = useTranslation()
  const online = useOnline()
  const update = useUpdate()
  if (online && !update.available) return null
  return (
    <div className="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] print:hidden">
      {!online && (
        <p role="status" className="pointer-events-auto flex max-w-md items-center gap-2 rounded-full bg-deep-harbor px-4 py-2 text-sm text-white shadow-lg">
          <WifiOff size={16} aria-hidden />{t('pwa.offline')}
        </p>
      )}
      {update.available && (
        <p role="status" className="pointer-events-auto flex max-w-md flex-wrap items-center gap-3 rounded-full bg-white px-4 py-2 text-sm shadow-lg ring-1 ring-fog">
          <RefreshCw size={16} aria-hidden />{t('pwa.update')}
          <button type="button" className="rounded-full bg-brand px-3 py-1 font-bold text-white" onClick={update.apply}>{t('pwa.reload')}</button>
        </p>
      )}
    </div>
  )
}
