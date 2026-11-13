import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import AuthShell from '../../components/shared/AuthShell'
import FormField from '../../components/shared/FormField'
import PasswordInput from '../../components/shared/PasswordInput'
import saApi, { setSaToken } from './saApi'
import { errorOf } from './useSuperAdmin'

const inputClass = 'w-full rounded border p-2'

// Two steps: email and password, then the 6-digit code that was e-mailed. Not linked from any public page.
export default function SuperAdminLoginPage({ onSignedIn }) {
  const { t } = useTranslation()
  const [step, setStep] = useState('password')
  const [form, setForm] = useState({ email: '', password: '', code: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (name) => (e) => setForm({ ...form, [name]: e.target.value })

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      if (step === 'password') {
        await saApi.post('/auth/login', { email: form.email, password: form.password })
        setStep('code')
      } else {
        const { data } = await saApi.post('/auth/verify', { email: form.email, code: form.code.trim() })
        setSaToken(data.token)
        onSignedIn?.(data.user)
      }
    } catch (err) {
      setError(errorOf(err, t('superAdmin.genericError')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthShell>
      <form onSubmit={submit} className="auth-card space-y-4">
        <h1 className="text-xl font-bold">{t('superAdmin.loginTitle')}</h1>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {step === 'password' ? (
          <>
            <FormField label={t('superAdmin.email')}>
              <input type="email" dir="ltr" autoComplete="username" required className={inputClass} value={form.email} onChange={set('email')} />
            </FormField>
            <FormField label={t('superAdmin.password')}>
              <PasswordInput autoComplete="current-password" required value={form.password} onChange={set('password')} />
            </FormField>
            <button disabled={busy} className="cta w-full bg-brand text-white disabled:opacity-60">{t('superAdmin.continue')}</button>
          </>
        ) : (
          <>
            <p className="text-sm text-slate-600">{t('superAdmin.codeSent', { email: form.email })}</p>
            <FormField label={t('superAdmin.code')}>
              <input inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} dir="ltr" required autoFocus className={`${inputClass} text-center text-lg tracking-widest`} value={form.code} onChange={set('code')} />
            </FormField>
            <button disabled={busy} className="cta w-full bg-brand text-white disabled:opacity-60">{t('superAdmin.signIn')}</button>
            <button type="button" className="w-full text-sm underline" onClick={() => { setStep('password'); setError(''); setForm({ ...form, code: '' }) }}>{t('superAdmin.back')}</button>
          </>
        )}
      </form>
    </AuthShell>
  )
}
