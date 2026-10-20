import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../features/Auth/AuthProvider'

export const homeFor = (role) => `/${role}`

export const CHANGE_PASSWORD_PATH = '/change-password'

export function RequireAuth() {
  const { user, loading } = useAuth()
  const { t } = useTranslation()
  const { pathname } = useLocation()
  if (loading) return <p className="p-6">{t('common.loading')}</p>
  if (!user) return <Navigate to="/login" replace />
  // Accounts created by the admin start with the default password: nothing else is available until it is changed.
  if (user.must_change_password && pathname !== CHANGE_PASSWORD_PATH) return <Navigate to={CHANGE_PASSWORD_PATH} replace />
  return <Outlet />
}

export function RequireRole({ role }) {
  const { user } = useAuth()
  const { t } = useTranslation()
  if (user.role !== role) {
    return <p className="p-6 text-red-600">{t('errors.forbidden')}</p>
  }
  return <Outlet />
}
