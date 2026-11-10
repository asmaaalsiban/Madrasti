import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { House, Images, Megaphone, MessageCircle } from 'lucide-react'
import useUnreadChat from '../../features/Chat/hooks/useUnreadChat'
import useUnreadAnnouncements from '../../features/Announcements/hooks/useUnreadAnnouncements'
import { useAuth } from '../../features/Auth/AuthProvider'
import { isHidden } from '../../features/StaffAccess/staffFeatures'

// The bottom tab bar of phones and tablets (see `.tabbar` in index.css: it never shows on a computer): four destinations of equal weight,
// an icon above a short label, the current one tinted and the others muted, a count badge on the inbox. A <nav> of links, the current one
// has aria-current="page". The bar clears the home indicator of an iPhone (safe-area inset). Switching is instant: no slide, no push.
export default function BottomTabBar() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const unreadChat = useUnreadChat()
  const unreadAnnouncements = useUnreadAnnouncements()
  const base = `/${user.role}`
  const tabs = [
    { to: base, label: t('tabbar.home'), icon: House, end: true },
    { to: `${base}/chat`, label: t('tabbar.inbox'), icon: MessageCircle, count: unreadChat, hide: '/teacher/chat' },
    { to: `${base}/announcements`, label: t('tabbar.news'), icon: Megaphone, count: unreadAnnouncements, hide: '/teacher/announcements' },
    { to: `${base}/stories`, label: t('tabbar.feed'), icon: Images, hide: '/teacher/stories' },
  ].filter((tab) => !tab.hide || !isHidden(user, tab.hide))
  return (
    <nav aria-label={t('tabbar.label')} className="tabbar print:hidden">
      <ul className="mx-auto flex max-w-xl items-stretch justify-around">
        {tabs.map(({ to, label, icon: Icon, end, count }) => (
          <li key={to} className="flex-1">
            <NavLink to={to} end={end} className={({ isActive }) => `relative flex min-h-14 flex-col items-center justify-center gap-0.5 px-2 py-1.5 text-[11px] font-medium transition-colors ${isActive ? 'text-brand' : 'text-slate-500'}`}>
              <span className="relative">
                <Icon size={24} aria-hidden strokeWidth={2} />
                {count > 0 && <span aria-label={t('tabbar.unread', { count })} className="absolute -top-1.5 -end-2.5 grid min-w-4.5 place-items-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-4 text-white" dir="ltr">{count > 99 ? '99+' : count}</span>}
              </span>
              <span>{label}</span>
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}
