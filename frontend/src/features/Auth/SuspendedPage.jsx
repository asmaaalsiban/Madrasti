import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import AuthShell from '../../components/shared/AuthShell'
import { useSchool } from '../../tenant/useSchool'

// Shown when the platform has blocked the school. Public: the school answer and its logo stay open for a blocked school.
export default function SuspendedPage() {
  const { t } = useTranslation()
  const { data: school } = useSchool()
  return (
    <AuthShell name={school?.name} logo={school?.logo_url}>
      <section className="auth-card space-y-4 break-words">
        <h1 className="text-2xl font-extrabold">{t('suspended.title')}</h1>
        {school?.name && <p className="font-bold">{school.name}</p>}
        <p className="text-slate-600">{t('suspended.message')}</p>
        <Link to="/login" className="cta block w-full bg-brand text-center text-white">{t('suspended.signIn')}</Link>
      </section>
    </AuthShell>
  )
}
