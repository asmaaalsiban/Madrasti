import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { currentSubscription, disablePush, enablePush, needsHomeScreen, pushSupported } from './push'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

// "Notifications on this phone": turns web push on or off for this device (shown inside the bell).
export default function PushToggle() {
  const { t } = useTranslation()
  const [state, setState] = useState('checking') // checking | off | on | denied
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const config = useQuery({ queryKey: ['push', 'config'], queryFn: async () => (await api.get('/push/config')).data.data, enabled: pushSupported() })

  useEffect(() => {
    if (!pushSupported()) return
    currentSubscription().then((s) => setState(Notification.permission === 'denied' ? 'denied' : s ? 'on' : 'off')).catch(() => setState('off'))
  }, [])

  if (!config.data?.enabled) return null
  if (!pushSupported()) return needsHomeScreen() ? <p className="border-t p-2 text-xs text-slate-500">{t('notifications.push.addToHome')}</p> : null

  const toggle = async () => {
    setBusy(true)
    setError('')
    try {
      if (state === 'on') {
        await disablePush()
        setState('off')
      } else {
        setState(await enablePush(config.data.public_key) === 'on' ? 'on' : 'denied')
      }
    } catch {
      setError(t('admin.actions.error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-1 border-t p-2 text-sm">
      {state === 'denied' ? <p className="text-xs text-amber-700">{t('notifications.push.denied')}</p> : (
        <InteractiveHoverButton variant="outline" type="button" disabled={busy || state === 'checking'} className="w-full px-3 py-1 text-start disabled:opacity-60" onClick={toggle}>
          {state === 'on' ? t('notifications.push.disable') : t('notifications.push.enable')}
        </InteractiveHoverButton>
      )}
      {state === 'on' && <p role="status" className="text-xs text-green-700">{t('notifications.push.on')}</p>}
      {error && <p role="alert" className="text-xs text-red-700">{error}</p>}
    </div>
  )
}
