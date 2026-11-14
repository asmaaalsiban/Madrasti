import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api, setToken } from '../../../api/client'
import { useAuth } from '../AuthProvider'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// A parent who signs in with a phone number adds their own email: a code is sent to it and typed here.
// If that email already belongs to another account of the same parent, the accounts are merged (the server returns a new token).
export default function AddEmailForm({ onDone, onSkip }) {
  const { t } = useTranslation()
  const { updateUser } = useAuth()
  const [step, setStep] = useState('email') // email | code | done
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [merged, setMerged] = useState(false)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const run = async (action) => {
    setBusy(true)
    setError('')
    try { await action() } catch (err) { setError(errorOf(err, t('admin.actions.error'))) } finally { setBusy(false) }
  }
  const sendCode = (e) => { e?.preventDefault(); return run(async () => { await api.post('/auth/email/request', { email }); setStep('code') }) }
  const confirm = (e) => {
    e.preventDefault()
    return run(async () => {
      const { data } = await api.post('/auth/email/confirm', { email, code })
      if (data.token) setToken(data.token) // after a merge the parent continues as the account that already had this email
      updateUser(data.user)
      setMerged(data.merged)
      setStep('done')
    })
  }

  return (
    <div className="space-y-3">
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      {step === 'email' && (
        <form onSubmit={sendCode} className="space-y-3">
          <p className="text-sm text-slate-600">{t('auth.addEmail.help')}</p>
          <label className="block text-sm">{t('auth.addEmail.email')}<input required type="email" dir="ltr" autoComplete="email" className="mt-1 w-full rounded border p-2" value={email} onChange={(e) => setEmail(e.target.value)} /></label>
          <div className="flex flex-wrap gap-2">
            <InteractiveHoverButton disabled={busy} className="px-4 py-2 disabled:opacity-60">{t('auth.addEmail.sendCode')}</InteractiveHoverButton>
            {onSkip && <button type="button" className="rounded border px-4 py-2" onClick={onSkip}>{t('auth.addEmail.later')}</button>}
          </div>
        </form>
      )}

      {step === 'code' && (
        <form onSubmit={confirm} className="space-y-3">
          <p role="status" className="rounded bg-green-50 p-2 text-sm text-green-800">{t('auth.addEmail.sent', { email })}</p>
          <label className="block text-sm">{t('auth.forgot.code')}
            <input required inputMode="numeric" autoComplete="one-time-code" maxLength={6} dir="ltr" className="mt-1 w-full rounded border p-2 text-center text-xl tracking-widest" value={code} onChange={(e) => setCode(e.target.value)} />
          </label>
          <div className="flex flex-wrap gap-2">
            <InteractiveHoverButton disabled={busy} className="px-4 py-2 disabled:opacity-60">{t('auth.addEmail.confirm')}</InteractiveHoverButton>
            <button type="button" disabled={busy} className="rounded border px-4 py-2" onClick={() => sendCode()}>{t('auth.forgot.resend')}</button>
            <button type="button" className="rounded border px-4 py-2" onClick={() => { setStep('email'); setCode('') }}>{t('auth.addEmail.change')}</button>
          </div>
        </form>
      )}

      {step === 'done' && (
        <div className="space-y-3">
          <p role="status" className="rounded bg-green-50 p-3 text-green-800">{merged ? t('auth.addEmail.merged') : t('auth.addEmail.saved', { email })}</p>
          <InteractiveHoverButton type="button" className="px-4 py-2" onClick={onDone}>{t('auth.addEmail.continue')}</InteractiveHoverButton>
        </div>
      )}
    </div>
  )
}
