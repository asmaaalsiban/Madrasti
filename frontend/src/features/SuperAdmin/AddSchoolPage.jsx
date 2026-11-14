import { useState } from 'react'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { DateInput } from '../../components/shared/DateInput'
import FormField from '../../components/shared/FormField'
import PasswordInput from '../../components/shared/PasswordInput'
import { normalizeSchoolCode } from '../../tenant/school'
import { errorOf, fieldErrors, useCreateSchool } from './useSuperAdmin'
import { IconActionButton } from '../../components/ui/icon-action-button'
import { Undo2 } from 'lucide-react'

const EMPTY = { name: '', id: '', admin_name: '', admin_email: '', admin_password: '', max_students: '', subscription_ends_at: '', default_locale: 'ar' }
const inputClass = 'w-full rounded border p-2'

// Add a school: its name and code, its first administrator, the student limit and the date it has paid until.
export default function AddSchoolPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const create = useCreateSchool()
  const [form, setForm] = useState(EMPTY)
  const errors = create.isError ? fieldErrors(create.error) : {}
  const set = (name) => (e) => setForm({ ...form, [name]: e.target.value })

  const submit = (e) => {
    e.preventDefault()
    const { max_students: limit, subscription_ends_at: ends, ...rest } = form
    create.mutate(
      { ...rest, id: normalizeSchoolCode(form.id), max_students: limit === '' ? null : Number(limit), subscription_ends_at: ends || null },
      { onSuccess: (school) => navigate(`/super-admin/schools/${school.id}`) },
    )
  }

  return (
    <section className="mx-auto max-w-xl space-y-4">
      <IconActionButton as={Link} icon={Undo2} tone="slate" label={t('superAdmin.backToSchools')} to="/super-admin/schools" />
      <form onSubmit={submit} className="space-y-4 rounded-lg border bg-white p-4">
        <h1 className="text-xl font-bold">{t('superAdmin.addSchool')}</h1>
        {create.isError && !Object.keys(errors).length && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{errorOf(create.error, t('superAdmin.genericError'))}</p>}
        <FormField label={t('superAdmin.schoolName')} error={errors.name?.[0]}><input required className={inputClass} value={form.name} onChange={set('name')} /></FormField>
        <FormField label={t('superAdmin.schoolCode')} hint={t('superAdmin.schoolCodeHint')} error={errors.id?.[0]}><input required dir="ltr" autoCapitalize="none" className={inputClass} value={form.id} onChange={set('id')} /></FormField>
        <FormField label={t('superAdmin.defaultLocale')} error={errors.default_locale?.[0]}>
          <select className={inputClass} value={form.default_locale} onChange={set('default_locale')}><option value="ar">{t('superAdmin.arabic')}</option><option value="en">{t('superAdmin.english')}</option></select>
        </FormField>
        <FormField label={t('superAdmin.adminName')} error={errors.admin_name?.[0]}><input required className={inputClass} value={form.admin_name} onChange={set('admin_name')} /></FormField>
        <FormField label={t('superAdmin.adminEmail')} error={errors.admin_email?.[0]}><input type="email" required dir="ltr" className={inputClass} value={form.admin_email} onChange={set('admin_email')} /></FormField>
        <FormField label={t('superAdmin.adminPassword')} error={errors.admin_password?.[0]}><PasswordInput required minLength={8} autoComplete="new-password" value={form.admin_password} onChange={set('admin_password')} /></FormField>
        <FormField label={t('superAdmin.maxStudents')} hint={t('superAdmin.maxStudentsHint')} error={errors.max_students?.[0]}><input type="number" min="1" dir="ltr" className={inputClass} value={form.max_students} onChange={set('max_students')} /></FormField>
        <FormField label={t('superAdmin.endDate')} hint={t('superAdmin.endDateHint')} error={errors.subscription_ends_at?.[0]}><DateInput className={inputClass} value={form.subscription_ends_at} onChange={set('subscription_ends_at')} /></FormField>
        <InteractiveHoverButton disabled={create.isPending} className="w-full py-3 text-base disabled:opacity-60">{t('superAdmin.addSchool')}</InteractiveHoverButton>
      </form>
    </section>
  )
}
