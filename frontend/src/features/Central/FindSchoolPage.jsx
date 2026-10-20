import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import FormField from '../../components/shared/FormField'
import CentralShell from './CentralShell'
import { goTo, isValidSchoolCode, normalizeSchoolCode, setSchool } from '../../tenant/school'
import { schoolExists } from './api'

export default function FindSchoolPage() {
  const { t } = useTranslation()
  const [value, setValue] = useState('')
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
