import { Link, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import BackButton from '../components/shared/BackButton'
import IconButton from '../components/shared/IconButton'
import UserMenu from '../components/shared/UserMenu'
import { Megaphone, MessageCircle } from 'lucide-react'
import useUnreadChat from '../features/Chat/hooks/useUnreadChat'
import useUnreadAnnouncements from '../features/Announcements/hooks/useUnreadAnnouncements'
import { useAuth } from '../features/Auth/AuthProvider'
import NotificationBell from '../features/Notifications/NotificationBell'
import { useSchool } from '../tenant/useSchool'

// Mobile-first shell shared by all four roles.
export default function DashboardLayout() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const { data: school } = useSchool()
  const unread = useUnreadAnnouncements()
  const unreadChat = useUnreadChat()
  const subject = user.subject ? user.subject[`name_${i18n.language}`] || user.subject.name_ar : null
  const isHead = user.staff_type === 'head_of_department'
  // "Student: Ahmad Khaled · 5-A", "Teacher: Sara · Math", "Head of department Math: Omar", "Parent: Mohammad".
  const label = isHead ? [t('staff.types.head_of_department'), subject].filter(Boolean).join(' ') : t(`roles.${user.role}`)
  const detail = user.role === 'student' ? user.classroom : isHead ? null : subject

  return (
    <div className="min-h-screen">
      <header className="sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-fog bg-snow px-4 py-2.5 print:hidden">
        <Link to={`/${user.role}`} className="flex min-w-0 items-center gap-2">
          {school?.logo && <img src={school.logo} alt="" className="h-9 w-9 rounded-xl object-cover" />}
          <span className="truncate text-lg font-extrabold text-deep-harbor">{school?.name ?? t('common.appName')}</span>
        </Link>
        <div className="flex items-center gap-2">
          <IconButton to={`/${user.role}/chat`} icon={MessageCircle} label={t('chat.title')} count={unreadChat} />
          <IconButton to={`/${user.role}/announcements`} icon={Megaphone} label={t('announcements.title')} count={unread} />
          <NotificationBell />
          <UserMenu detail={[label, detail].filter(Boolean).join(' · ')} />
        </div>
      </header>
      <main className="mx-auto max-w-5xl p-4">
        <BackButton />
        <Outlet />
      </main>
    </div>
  )
}
