import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Download, Share, SquarePlus } from 'lucide-react'
import Modal from '../components/shared/Modal'
import { useInstall } from './pwa'
import { InteractiveHoverButton } from '../components/ui/interactive-hover-button'

// "Install the app" in the user menu: one tap where the browser allows it, and instructions for iPhone (Safari has no install button).
export default function InstallMenuItem({ className, onDone }) {
  const { t } = useTranslation()
  const { mode, install } = useInstall()
  const [help, setHelp] = useState(false)
  if (!mode) return null
  return (
    <>
      <button type="button" role="menuitem" className={className} onClick={() => { if (mode === 'prompt') { install(); onDone?.() } else setHelp(true) }}>
        <Download aria-hidden size={18} />{t('pwa.install')}
      </button>
      <Modal open={help} title={t('pwa.iosTitle')} onClose={() => { setHelp(false); onDone?.() }}>
        <ol className="space-y-3 text-sm">
          <li className="flex items-start gap-3"><Share aria-hidden size={20} className="mt-0.5 shrink-0 text-brand" /><span>{t('pwa.iosStep1')}</span></li>
          <li className="flex items-start gap-3"><SquarePlus aria-hidden size={20} className="mt-0.5 shrink-0 text-brand" /><span>{t('pwa.iosStep2')}</span></li>
          <li className="flex items-start gap-3"><Download aria-hidden size={20} className="mt-0.5 shrink-0 text-brand" /><span>{t('pwa.iosStep3')}</span></li>
        </ol>
        <div className="mt-4 flex justify-end"><InteractiveHoverButton variant="outline" type="button" className="px-4 py-1.5" onClick={() => { setHelp(false); onDone?.() }}>{t('transport.close')}</InteractiveHoverButton></div>
      </Modal>
    </>
  )
}
