import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import LanguageSwitcher from './LanguageSwitcher'

// The frame of every sign-in page (code, sign in, forgot password, register): the landing's deep navy, a small header, and the
// page's own card in the middle. `top` is extra header content (e.g. the change-school button); `name` and `logo` show the school.
export default function AuthShell({ children, name, logo, top = null, width = 'max-w-sm' }) {
  const { t } = useTranslation()
  const label = name ?? t('common.appName')
  return (
    <div className="auth-bg min-h-screen">
      <header className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-4">
        <Link to="/" className="flex items-center gap-2 text-white">
          {logo ? <img src={logo} alt="" className="h-9 w-9 rounded-xl object-cover" /> : <span aria-hidden className="grid h-9 w-9 place-items-center rounded-xl bg-white/15 font-extrabold ring-1 ring-white/25">{label.trim().charAt(0)}</span>}
          <span className="text-lg font-extrabold">{label}</span>
        </Link>
        <div className="flex items-center gap-2 rounded-full bg-white/90 p-1 ps-3 text-deep-harbor shadow-lg">{top}<LanguageSwitcher /></div>
      </header>
      <main id="main" tabIndex={-1} className={`mx-auto ${width} px-4 pb-12 pt-6 outline-none`}>{children}</main>
    </div>
  )
}
