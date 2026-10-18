import { useTranslation } from 'react-i18next'
import { useAuth } from '../AuthProvider'

// Placeholder dashboard, replaced per role as modules are built.
export default function RoleHome() {
  const { t } = useTranslation()
  const { user } = useAuth()
  return (
    <section>
      <h1 className="text-xl font-bold">{t('dashboard.welcome', { name: user.name })}</h1>
      <p className="mt-2 text-slate-600">{t('dashboard.soon', { role: t(`roles.${user.role}`) })}</p>
    </section>
  )
}
