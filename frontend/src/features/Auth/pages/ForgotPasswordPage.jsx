import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import AuthShell from '../../../components/shared/AuthShell'
import PasswordInput from '../../../components/shared/PasswordInput'
import { useSchool } from '../../../tenant/useSchool'

// Forgot my password: 1) enter the login, a code is e-mailed (a student's code goes to their parent), 2) enter the code and a new password.
export default function ForgotPasswordPage() {
  const { t } = useTranslation()
  const { data: school } = useSchool()
  const [step, setStep] = useState('login') // login | code | done
  const [form, setForm] = useState({ login: '', code: '', password: '', password_confirmation: '' })
  const [error, setError] = useState('')
  const [info, setInfo] = useState('')
  const [busy, setBusy] = useState(false)
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  const call = async (fn) => {
    setBusy(true)
    setError('')
    try {
      await fn()
    } catch (err) {
      const errors = err.response?.data?.errors ?? {}
      setError(Object.values(errors)[0]?.[0] ?? err.response?.data?.message ?? t('auth.failed'))
    } finally {
      setBusy(false)
    }
  }

  const requestCode = (e) => {
    e?.preventDefault()
    return call(async () => {
      const { data } = await api.post('/auth/forgot-password', { login: form.login })
      setInfo(data.message)
      setStep('code')
    })
  }
  const resetPassword = (e) => {
    e.preventDefault()
    return call(async () => {
      const { data } = await api.post('/auth/reset-password', form)
      setInfo(data.message)
      setStep('done')
    })
  }

  return (
    <AuthShell name={school?.name} logo={school?.logo_url}>
      <div className="auth-card space-y-4">
        <Link to="/login" className="text-sm text-brand">{t('auth.forgot.backToLogin')}</Link>
        <h1 className="text-xl font-bold">{school?.name ?? t('common.appName')}</h1>
        <h2 className="text-lg">{t('auth.forgot.title')}</h2>

        {step === 'login' && (
          <form onSubmit={requestCode} className="space-y-4">
            <p className="text-sm text-slate-600">{t('auth.forgot.help')}</p>
            <p className="text-xs text-slate-500">{t('auth.forgot.phoneOnly')}</p>
            <label className="block text-sm">{t('auth.login')}<input required className="mt-1 w-full rounded border p-2" value={form.login} onChange={set('login')} /></label>
            {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
            <button disabled={busy} className="cta w-full bg-brand text-white disabled:opacity-60">{t('auth.forgot.sendCode')}</button>
          </form>
        )}

        {step === 'code' && (
          <form onSubmit={resetPassword} className="space-y-4">
            <p role="status" className="rounded bg-green-50 p-2 text-sm text-green-800">{info}</p>
            <label className="block text-sm">{t('auth.forgot.code')}
              <input required inputMode="numeric" autoComplete="one-time-code" maxLength={6} dir="ltr" className="mt-1 w-full rounded border p-2 text-center text-xl tracking-widest" value={form.code} onChange={set('code')} />
            </label>
            <label className="block text-sm">{t('auth.changePassword.new')}<PasswordInput className="mt-1" required value={form.password} onChange={set('password')} /></label>
            <label className="block text-sm">{t('auth.changePassword.confirm')}<PasswordInput className="mt-1" required value={form.password_confirmation} onChange={set('password_confirmation')} /></label>
            {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
            <button disabled={busy} className="cta w-full bg-brand text-white disabled:opacity-60">{t('auth.forgot.setPassword')}</button>
            <button type="button" className="w-full text-sm text-brand underline" disabled={busy} onClick={() => requestCode()}>{t('auth.forgot.resend')}</button>
          </form>
        )}

        {step === 'done' && (
          <div className="space-y-4">
            <p role="status" className="rounded bg-green-50 p-2 text-sm text-green-800">{info}</p>
            <Link to="/login" className="cta block w-full bg-brand text-center text-white">{t('auth.submit')}</Link>
          </div>
        )}
      </div>
    </AuthShell>
  )
}
