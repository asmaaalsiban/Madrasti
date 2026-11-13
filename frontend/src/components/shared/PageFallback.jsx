import { useTranslation } from 'react-i18next'

// Shown while the code of a page is still downloading (every page loads on demand). Light on purpose: a soft pulsing bar, no layout jump.
export default function PageFallback() {
  const { t } = useTranslation()
  return (
    <div role="status" aria-live="polite" className="grid min-h-[40vh] place-items-center">
      <span className="sr-only">{t('common.loading')}</span>
      <span aria-hidden className="h-1.5 w-40 animate-pulse rounded-full bg-slate-300/60" />
    </div>
  )
}
