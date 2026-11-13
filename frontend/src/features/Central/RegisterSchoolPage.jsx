import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import FormField from '../../components/shared/FormField'
import { normalizeSchoolCode, goTo, setSchool } from '../../tenant/school'
import { loginAdmin, registerSchool } from './api'
import CentralShell from './CentralShell'
import { useRegistration } from './useRegistration'

const EMPTY = { school_name: '', code: '', admin_name: '', admin_email: '', password: '', password_confirmation: '' }
const inputClass = 'w-full rounded border p-2'

export default function RegisterSchoolPage() {
  const { t } = useTranslation()
  const registration = useRegistration()
  const [form, setForm] = useState(EMPTY)
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [busy, setBusy] = useState(false)

  const set = (name) => (e) => setForm({ ...form, [name]: e.target.value })

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setFormError('')
    try {
      const school = await registerSchool({ ...form, code: normalizeSchoolCode(form.code) })
      setSchool(school.code)
      // Sign the new admin straight in; if that fails the school sign-in page is still one click away.
      try { await loginAdmin(form.admin_email, form.password) } catch { /* sign in manually */ }
      goTo('/')
    } catch (err) {
      const fieldErrors = err.response?.data?.errors
      setErrors(fieldErrors ?? {})
      // 403 (closed) and 429 (throttled) carry a translated message from the server.
      const status = err.response?.status
      if (!fieldErrors) setFormError(([403, 429].includes(status) && err.response.data?.message) || t('central.genericError'))
      setBusy(false)
    }
  }

  const field = (name, label, props = {}) => (
    <FormField label={t(label)} error={errors[name]?.[0]}>
      <input className={inputClass} value={form[name]} onChange={set(name)} required {...props} />
    </FormField>
  )

  if (registration.loading) return <CentralShell><p className="auth-card">{t('common.loading')}</p></CentralShell>
  if (!registration.enabled) {
    return (
      <CentralShell>
        <div className="auth-card space-y-4">
          <h1 className="text-xl font-bold">{t('central.registrationClosedTitle')}</h1>
          <p className="text-slate-600">{t('central.registrationClosed')}</p>
          <Link to="/find-school" className="cta inline-block bg-brand text-white">{t('central.signIn')}</Link>
        </div>
      </CentralShell>
    )
  }

  return (
    <CentralShell>
      <form onSubmit={submit} className="auth-card space-y-4">
        <h1 className="text-xl font-bold">{t('central.registerTitle')}</h1>
        {formError && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{formError}</p>}
        {field('school_name', 'central.schoolName')}
        {field('code', 'central.schoolCode', { dir: 'ltr', autoCapitalize: 'none' })}
        <p className="-mt-2 text-xs text-slate-500">{t('central.schoolCodeHint')}</p>
        {field('admin_name', 'central.adminName')}
        {field('admin_email', 'central.email', { type: 'email', dir: 'ltr' })}
        {field('password', 'central.password', { type: 'password', dir: 'ltr' })}
        {field('password_confirmation', 'central.passwordConfirmation', { type: 'password', dir: 'ltr' })}
        <button disabled={busy} className="cta w-full bg-brand text-white disabled:opacity-60">{t('central.createAccount')}</button>
      </form>
    </CentralShell>
  )
}
