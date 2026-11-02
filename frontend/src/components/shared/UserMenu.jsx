import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ChevronDown, KeyRound, Languages, LogOut, Repeat } from 'lucide-react'
import { useAuth } from '../../features/Auth/AuthProvider'
import { clearSchool, goTo } from '../../tenant/school'

const item = 'flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start text-sm text-ink hover:bg-slate-50'

// The person's own menu: avatar and name, and under it language, password, school and sign out (instead of a row of buttons).
export default function UserMenu({ detail }) {
  const { t, i18n } = useTranslation()
  const { user, logout } = useAuth()
  const [open, setOpen] = useState(false)
  const box = useRef(null)

  useEffect(() => {
    if (!open) return undefined
    const away = (e) => { if (box.current && !box.current.contains(e.target)) setOpen(false) }
    const esc = (e) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', away)
    document.addEventListener('keydown', esc)
    return () => { document.removeEventListener('mousedown', away); document.removeEventListener('keydown', esc) }
  }, [open])

  const initial = (user?.name ?? '?').trim().charAt(0)
  return (
    <div className="relative" ref={box}>
      <button type="button" aria-expanded={open} aria-haspopup="menu" aria-label={t('common.account')} className="flex items-center gap-2 rounded-full border bg-snow py-1 ps-1 pe-3" onClick={() => setOpen((v) => !v)}>
        <span aria-hidden className="grid h-8 w-8 place-items-center rounded-full bg-deep-harbor text-sm font-bold text-white">{initial}</span>
        <span className="hidden min-w-0 text-start leading-tight sm:block">
          <span className="block max-w-40 truncate text-sm font-bold text-deep-harbor">{user?.name}</span>
          {detail && <span className="block max-w-40 truncate text-xs text-slate-500">{detail}</span>}
        </span>
        <ChevronDown aria-hidden size={16} className="text-slate-500" />
      </button>
      {open && (
        <div role="menu" className="absolute end-0 z-40 mt-2 w-64 rounded-2xl border bg-snow p-2 shadow-lg">
          <div className="px-3 pb-2 pt-1">
            <p className="font-bold text-deep-harbor">{user?.name}</p>
            {detail && <p className="text-xs text-slate-500">{detail}</p>}
          </div>
          <hr className="mb-1" />
          <button type="button" role="menuitem" className={item} onClick={() => i18n.changeLanguage(i18n.language === 'ar' ? 'en' : 'ar')}><Languages aria-hidden size={18} />{t('common.language')}</button>
          <Link role="menuitem" to="/change-password" className={item} onClick={() => setOpen(false)}><KeyRound aria-hidden size={18} />{t('auth.changePassword.link')}</Link>
          <button type="button" role="menuitem" className={item} onClick={() => { clearSchool(); goTo('/find-school') }}><Repeat aria-hidden size={18} />{t('central.switchSchool')}</button>
          <hr className="my-1" />
          <button type="button" role="menuitem" className={`${item} text-red-700`} onClick={logout}><LogOut aria-hidden size={18} />{t('common.logout')}</button>
        </div>
      )}
    </div>
  )
}
