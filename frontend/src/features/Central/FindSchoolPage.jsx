import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import BackArrow from '../../components/shared/BackArrow'
import FormField from '../../components/shared/FormField'
import CentralShell from './CentralShell'
import { goTo, isValidSchoolCode, normalizeSchoolCode, setSchool } from '../../tenant/school'
import { schoolExists } from './api'

export default function FindSchoolPage() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  // The sign-in page sends the current code back so a typo can be fixed instead of retyping everything.
  const suggested = normalizeSchoolCode(params.get('code') ?? '')
  const [value, setValue] = useState(isValidSchoolCode(suggested) ? suggested : '')
  const [error, setError] = useState('')

  const submit = async (e) => {
    e.preventDefault()
    const code = normalizeSchoolCode(value)
    if (!isValidSchoolCode(code)) {
      setError(t('central.invalidCode'))
      return
    }
    try {
      if (!(await schoolExists(code))) {
        setError(t('central.schoolNotFound'))
        return
      }
    } catch {
      setError(t('central.genericError'))
      return
    }
    setSchool(code)
    goTo('/login')
  }

  return (
    <CentralShell>
      <Link to="/" className="mb-4 inline-flex items-center gap-2 text-sm text-brand">
        <BackArrow />
        {t('central.back')}
      </Link>
      <form onSubmit={submit} className="space-y-4 rounded-lg bg-white p-6 shadow">
        <h1 className="text-xl font-bold">{t('central.findTitle')}</h1>
        <p className="text-sm text-slate-600">{t('central.findText')}</p>
        <FormField label={t('central.schoolCode')} error={error}>
          <input className="w-full rounded border p-2" dir="ltr" autoCapitalize="none" value={value} onChange={(e) => setValue(e.target.value)} required />
        </FormField>
        <button className="w-full rounded bg-brand p-2 text-white">{t('central.continue')}</button>
      </form>
    </CentralShell>
  )
}
