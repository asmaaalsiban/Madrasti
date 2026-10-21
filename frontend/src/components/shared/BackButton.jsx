import { useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { homeFor } from '../../app/guards'
import { useAuth } from '../../features/Auth/AuthProvider'
import BackArrow from './BackArrow'

// "Back to the previous page" at the start corner of every signed-in page (right in Arabic, left in English).
// Not shown on the role's home page. A page opened directly has no previous page, so it goes home instead.
export default function BackButton() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { pathname, key } = useLocation()
  const { user } = useAuth()
  const home = homeFor(user?.role)
  if (!user || pathname === home) return null

  return (
    <button
      type="button"
      className="mb-3 inline-flex items-center gap-2 rounded border bg-white px-3 py-1.5 text-sm text-brand hover:bg-slate-50"
      onClick={() => (key === 'default' ? navigate(home) : navigate(-1))}
    >
      <BackArrow />
      {t('common.back')}
    </button>
  )
}
