import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { homeFor } from '../../../app/guards'
import BackArrow from '../../../components/shared/BackArrow'
import FormField from '../../../components/shared/FormField'
import CentralShell from '../../Central/CentralShell'
import { useAuth } from '../AuthProvider'

const EMPTY = { current_password: '', password: '', password_confirmation: '' }

// Every role can choose their own password. Accounts created by the admin start with the default one
// and are sent here until they change it.
export default function ChangePasswordPage() {
  const { t } = useTranslation()
  const { user, updateUser } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState(EMPTY)
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [busy, setBusy] = useState(false)
  const forced = Boolean(user?.must_change_password)

  const set = (name) => (e) => setForm({ ...form, [name]: e.target.value })

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setFormError('')
    try {
      const { data } = await api.post('/auth/change-password', form)
      updateUser(data.data)
      navigate(homeFor(user.role), { replace: true })
    } catch (err) {
      const fieldErrors = err.response?.data?.errors
      setErrors(fieldErrors ?? {})
      const status = err.response?.status
      if (!fieldErrors) setFormError(([403, 429].includes(status) && err.response.data?.message) || t('central.genericError'))
      setBusy(false)
    }
  }

  const field = (name, label) => (
    <FormField label={t(label)} error={errors[name]?.[0]}>
      <input type="password" dir="ltr" className="w-full rounded border p-2" value={form[name]} onChange={set(name)} required />
    </FormField>
  )

  return (
    <CentralShell>
      {!forced && (
        <Link to={homeFor(user.role)} className="mb-4 inline-flex items-center gap-2 text-sm text-brand">
          <BackArrow />
          {t('central.back')}
        </Link>
      )}
      <form onSubmit={submit} className="space-y-4 rounded-lg bg-white p-6 shadow">
        <h1 className="text-xl font-bold">{t('auth.changePassword.title')}</h1>
        {forced && <p className="rounded bg-amber-50 p-3 text-sm text-amber-900">{t('auth.changePassword.forced')}</p>}
        {formError && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{formError}</p>}
        {field('current_password', 'auth.changePassword.current')}
        {field('password', 'auth.changePassword.new')}
        {field('password_confirmation', 'auth.changePassword.confirm')}
        <button disabled={busy} className="w-full rounded bg-brand p-2 text-white disabled:opacity-60">{t('auth.changePassword.submit')}</button>
      </form>
    </CentralShell>
  )
}
