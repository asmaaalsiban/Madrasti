import { Navigate, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../features/Auth/AuthProvider'

export const homeFor = (role) => `/${role}`

export function RequireAuth() {
  const { user, loading } = useAuth()
  const { t } = useTranslation()
  if (loading) return <p className="p-6">{t('common.loading')}</p>
  return user ? <Outlet /> : <Navigate to="/login" replace />
}

export function RequireRole({ role }) {
  const { user } = useAuth()
  const { t } = useTranslation()
  if (user.role !== role) {
    return <p className="p-6 text-red-600">{t('errors.forbidden')}</p>
  }
  return <Outlet />
}
