import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useAuth } from '../../Auth/AuthProvider'
import NewChatDialog from '../components/NewChatDialog'
import NewGroupDialog from '../components/NewGroupDialog'
import Thread from '../components/Thread'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// Chat: the list of conversations on one side, the open conversation on the other (one at a time on a phone).
export default function ChatPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [params, setParams] = useSearchParams()
  const [dialog, setDialog] = useState(null) // 'chat' | 'group'
  const canGroup = ['admin', 'teacher'].includes(user.role)
  const active = Number(params.get('c')) || null

  const list = useQuery({ queryKey: ['chat', 'conversations'], queryFn: async () => (await api.get('/chat/conversations')).data.data, refetchInterval: 10000 })
  const current = (list.data ?? []).find((c) => c.id === active)
  const open = (id) => { setDialog(null); setParams(id ? { c: String(id) } : {}) }

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('chat.title')}</h1>
        <div className="flex gap-2">
          <InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={() => setDialog('chat')}>{t('chat.newChat')}</InteractiveHoverButton>
          {canGroup && <InteractiveHoverButton variant="outline" type="button" className="px-4 py-1.5 text-brand" onClick={() => setDialog('group')}>{t('chat.newGroup')}</InteractiveHoverButton>}
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-[18rem_1fr]">
        <ul className={`space-y-1 ${active ? 'hidden md:block' : ''}`} aria-label={t('chat.conversations')}>
          {list.isSuccess && list.data.length === 0 && <li className="rounded bg-white p-4 text-sm text-slate-600">{t('chat.none')}</li>}
          {(list.data ?? []).map((c) => (
            <li key={c.id}>
              <button type="button" aria-current={c.id === active} className={`block w-full rounded-lg border p-3 text-start ${c.id === active ? 'border-brand bg-brand/5' : 'bg-white'}`} onClick={() => open(c.id)}>
                <span className="flex items-center justify-between gap-2">
                  <span className="font-bold">{c.type === 'group' ? '👥 ' : ''}{c.title}</span>
                  {c.unread > 0 && <span className="rounded-full bg-brand px-2 text-xs text-white" aria-label={t('chat.unread', { count: c.unread })}>{c.unread}</span>}
                </span>
                {c.last_message && <span className="block truncate text-xs text-slate-500">{c.last_message.mine ? `${t('chat.you')}: ` : ''}{c.last_message.body || `📷 ${t('chat.photo')}`}</span>}
              </button>
            </li>
          ))}
        </ul>
        <div className={active ? '' : 'hidden md:block'}>
          {current ? <Thread key={current.id} conversation={current} onBack={() => open(null)} /> : <p className="hidden rounded-lg border bg-white p-6 text-center text-slate-500 md:block">{t('chat.pick')}</p>}
        </div>
      </div>

      {dialog === 'chat' && <NewChatDialog onClose={() => setDialog(null)} onOpened={open} />}
      {dialog === 'group' && <NewGroupDialog onClose={() => setDialog(null)} onOpened={open} />}
    </section>
  )
}
