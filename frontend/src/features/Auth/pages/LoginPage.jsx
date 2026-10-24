import PasswordInput from '../../../components/shared/PasswordInput'
import { useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from '../../../components/shared/LanguageSwitcher'
import ChangeSchoolCodeButton from '../../../components/shared/ChangeSchoolCodeButton'
import { CHANGE_PASSWORD_PATH, homeFor } from '../../../app/guards'
import { getSchool } from '../../../tenant/school'
import { useSchool } from '../../../tenant/useSchool'
import { useAuth } from '../AuthProvider'

export default function LoginPage() {
  const { t } = useTranslation()
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const { data: school } = useSchool()
  const [form, setForm] = useState({ login: '', password: '' })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  if (user) return <Navigate to={homeFor(user.role)} replace />

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      const u = await login(form.login, form.password)
      navigate(u.must_change_password ? CHANGE_PASSWORD_PATH : homeFor(u.role), { replace: true })
    } catch (err) {
      setError(err.response?.data?.errors?.login?.[0] ?? err.response?.data?.message ?? t('auth.failed'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center p-4">
      <form onSubmit={submit} className="w-full max-w-sm space-y-4 rounded-lg bg-white p-6 shadow">
        <div className="flex items-center justify-between gap-2">
          <ChangeSchoolCodeButton />
          <LanguageSwitcher />
        </div>
        <div>
          <h1 className="text-xl font-bold">{school?.name ?? t('common.appName')}</h1>
          <p dir="ltr" className="text-start text-sm text-slate-500">{getSchool()}</p>
        </div>
        <h2 className="text-lg">{t('auth.title')}</h2>
        <label className="block text-sm">
          {t('auth.login')}
          <input
            className="mt-1 w-full rounded border p-2"
            value={form.login}
            onChange={(e) => setForm({ ...form, login: e.target.value })}
            required
          />
        </label>
        <label className="block text-sm">
          {t('auth.password')}
          <PasswordInput
            className="mt-1"
            value={form.password}
            onChange={(e) => setForm({ ...form, password: e.target.value })}
            required
          />
        </label>
        {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
        <Link to="/forgot-password" className="block text-sm text-brand">{t('auth.forgot.link')}</Link>
        <button disabled={busy} className="w-full rounded bg-brand p-2 text-white disabled:opacity-60">
          {t('auth.submit')}
        </button>
      </form>
    </div>
  )
}
