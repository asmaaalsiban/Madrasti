import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import useBadges from '../Badges/useBadges'
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { Bell } from 'lucide-react'
import PushToggle from './PushToggle'

// The sentence of a notification, written in the reader's language from its kind and parameters.
export function notificationText(t, language, n) {
  const p = n.params ?? {}
  const pick = (key) => p[`${key}_${language}`] ?? p[`${key}_ar`]
  const kind = n.kind === 'installment' && p.overdue ? 'installment_overdue' : n.kind === 'chat_message' && p.group ? 'chat_group' : n.kind
  const text = t(`notifications.kinds.${kind}${kind === 'plan_reviewed' ? `.${p.decision}` : kind === 'survey_new' ? `.${p.type}` : ''}`, {
    subject: pick('subject'), title: p.title, teacher: p.teacher, student: pick('student'), badge: pick('badge'), icon: p.icon, note: p.note, sender: p.sender, group: p.group, amount: p.amount, due: p.due, month: p.month, name: p.name, date: p.date, defaultValue: t('notifications.kinds.unknown'),
  })
  const child = pick('child')
  return child ? `${text} (${child})` : text
}

// The bell: a counter of unread notifications (checked every 30 seconds) and a list; opening one marks it read and goes to its page.
export default function NotificationBell() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const box = useRef(null)
  // The counter comes with the shared header counters; the list is only fetched when the bell is opened.
  const unread = useBadges().notifications
  const { data } = useQuery({ queryKey: ['notifications'], enabled: open, queryFn: async () => (await api.get('/notifications')).data.data })
  const refresh = () => { client.invalidateQueries({ queryKey: ['notifications'] }); client.invalidateQueries({ queryKey: ['badges'] }) }
  const markRead = useMutation({ mutationFn: (id) => api.post(`/notifications/${id}/read`), onSuccess: refresh })
  const readAll = useMutation({ mutationFn: () => api.post('/notifications/read-all'), onSuccess: refresh })

  // Clicking anywhere outside the bell, or pressing Escape, closes the list.
  useEffect(() => {
    if (!open) return undefined
    const away = (e) => { if (box.current && !box.current.contains(e.target)) setOpen(false) }
    const esc = (e) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', away)
    document.addEventListener('touchstart', away)
    document.addEventListener('keydown', esc)
    return () => {
      document.removeEventListener('mousedown', away)
      document.removeEventListener('touchstart', away)
      document.removeEventListener('keydown', esc)
    }
  }, [open])

  const openOne = (n) => {
    if (!n.read) markRead.mutate(n.id)
    setOpen(false)
    if (n.link) navigate(n.link)
  }

  return (
    <div className="relative" ref={box}>
      <button type="button" className="relative grid h-10 w-10 place-items-center rounded-full border bg-snow text-deep-harbor" aria-expanded={open} aria-label={t('notifications.title')} title={t('notifications.title')} onClick={() => setOpen((v) => !v)}>
        <Bell aria-hidden size={20} />
        {unread > 0 && <span className="absolute -end-1 -top-1 grid min-w-5 place-items-center rounded-full bg-brand px-1 text-[11px] font-bold leading-5 text-white" aria-label={t('notifications.unread', { count: unread })}>{unread}</span>}
      </button>
      {open && (
        <div role="dialog" aria-label={t('notifications.title')} className="absolute end-0 z-40 mt-2 w-80 max-w-[90vw] rounded-lg border bg-white p-2 text-start text-slate-800 shadow-lg">
          <div className="flex items-center justify-between px-2 pb-2">
            <span className="font-bold">{t('notifications.title')}</span>
            {unread > 0 && <button type="button" className="text-xs text-brand underline" onClick={() => readAll.mutate()}>{t('notifications.readAll')}</button>}
          </div>
          {!data?.items?.length ? <p className="p-3 text-sm text-slate-500">{t('notifications.none')}</p> : (
            <ul className="max-h-96 divide-y overflow-y-auto">
              {data.items.map((n) => (
                <li key={n.id}>
                  <button type="button" className={`block w-full p-2 text-start text-sm hover:bg-slate-50 ${n.read ? 'text-slate-500' : 'font-medium'}`} onClick={() => openOne(n)}>
                    {!n.read && <span aria-hidden="true" className="me-1 text-brand">●</span>}
                    {notificationText(t, i18n.language, n)}
                    <span dir="ltr" className="block text-xs text-slate-400">{n.created_at.slice(0, 16).replace('T', ' ')}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          <PushToggle />
        </div>
      )}
    </div>
  )
}
