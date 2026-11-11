import { useTranslation } from 'react-i18next'
import { Images, Megaphone, MessageCircle } from 'lucide-react'
import IconButton from './IconButton'
import useUnreadChat from '../../features/Chat/hooks/useUnreadChat'
import useUnreadAnnouncements from '../../features/Announcements/hooks/useUnreadAnnouncements'
import NotificationBell from '../../features/Notifications/NotificationBell'
import { useAuth } from '../../features/Auth/AuthProvider'
import { isHidden } from '../../features/StaffAccess/staffFeatures'

// Everything about communication as round icons at the top of every account: chat, announcements, the class feed and the notifications bell.
// On phones and tablets the bottom tab bar already has chat, announcements and the feed, so only the bell stays at the top there (`.header-comm`
// in index.css is hidden under the same media query that shows the bar): never the same link twice on one screen.
export default function HeaderIcons() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const unread = useUnreadAnnouncements()
  const unreadChat = useUnreadChat()
  const base = `/${user.role}`
  return (
    <>
      <span className="header-comm flex items-center gap-1.5 sm:gap-2">
        {!isHidden(user, '/teacher/chat') && <IconButton to={`${base}/chat`} icon={MessageCircle} label={t('chat.title')} count={unreadChat} />}
        {!isHidden(user, '/teacher/announcements') && <IconButton to={`${base}/announcements`} icon={Megaphone} label={t('announcements.title')} count={unread} />}
        {!isHidden(user, '/teacher/stories') && <IconButton to={`${base}/stories`} icon={Images} label={t('stories.title')} />}
      </span>
      <NotificationBell />
    </>
  )
}
