import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../features/Auth/AuthProvider'
import { DOWNLOAD_PATH } from '../api/client'
import { useSchool } from '../tenant/useSchool'

export const homeFor = (role) => `/${role}`

export const CHANGE_PASSWORD_PATH = '/change-password'

export function RequireAuth() {
  const { user, loading } = useAuth()
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const { data: school, isLoading: schoolLoading } = useSchool()
  if (loading || (user && schoolLoading)) return <p className="p-6">{t('common.loading')}</p>
  if (!user) return <Navigate to="/login" replace />
  // Accounts created by the admin start with the default password: nothing else is available until it is changed (this comes
  // first: an administrator who still has it could not download the data of a school in its exit window before changing it).
  if (user.must_change_password && pathname !== CHANGE_PASSWORD_PATH) return <Navigate to={CHANGE_PASSWORD_PATH} replace />
  // A school in its exit window: its administrator gets only the download page (nothing of the app, so no blocked calls).
  if (user.role === 'admin' && school?.exit_window_ends_at && pathname !== DOWNLOAD_PATH && pathname !== CHANGE_PASSWORD_PATH) return <Navigate to={DOWNLOAD_PATH} replace />
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
