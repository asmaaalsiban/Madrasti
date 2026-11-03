import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ChatImage from './ChatImage'

const time = (iso) => iso.slice(11, 16)

// One conversation: the messages (refreshed every 4 seconds, opening them marks them read) and the box to write in.
export default function Thread({ conversation, onBack }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [text, setText] = useState('')
  const [photo, setPhoto] = useState(null)
  const [error, setError] = useState('')
  const input = useRef(null)
  const end = useRef(null)
  const id = conversation.id

  const messages = useQuery({ queryKey: ['chat', 'messages', id], queryFn: async () => (await api.get(`/chat/conversations/${id}/messages`)).data.data, refetchInterval: 4000 })
  const refresh = () => { client.invalidateQueries({ queryKey: ['chat'] }); client.invalidateQueries({ queryKey: ['badges'] }) }
  const count = messages.data?.length ?? 0
  useEffect(() => { end.current?.scrollIntoView?.({ block: 'end' }) }, [count])
  useEffect(() => { if (messages.isSuccess) { client.invalidateQueries({ queryKey: ['chat', 'conversations'] }); client.invalidateQueries({ queryKey: ['badges'] }) } }, [count, messages.isSuccess, client])

  const send = useMutation({
    mutationFn: () => {
      const data = new FormData()
      if (text.trim()) data.append('body', text.trim())
      if (photo) data.append('image', photo)
      return api.post(`/chat/conversations/${id}/messages`, data)
    },
    onSuccess: () => { setText(''); setPhoto(null); setError(''); refresh() },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || t('admin.actions.error')),
  })
  const remove = useMutation({ mutationFn: (mid) => api.delete(`/chat/messages/${mid}`), onSuccess: refresh })

  const ticks = (m) => {
    if (!m.mine) return null
    if (conversation.type === 'group') return <span aria-label={t('chat.readBy', { count: m.read_by, total: m.recipients })}>✓ {m.read_by}/{m.recipients}</span>
    return m.read ? <span className="text-sky-600" aria-label={t('chat.read')}>✓✓</span> : <span aria-label={t('chat.sent')}>✓</span>
  }

  return (
    <div className="flex h-full min-h-[28rem] flex-col rounded-lg border bg-white">
      <div className="flex items-center gap-2 border-b p-3">
        <button type="button" className="rounded border px-2 py-1 text-sm md:hidden" onClick={onBack}>{t('chat.back')}</button>
        <div>
          <p className="font-bold">{conversation.title}</p>
          <p className="text-xs text-slate-500">
            {conversation.type === 'group' ? t('chat.members', { count: conversation.members }) : [t(`roles.${conversation.other?.role}`), conversation.other?.label].filter(Boolean).join(' · ')}
          </p>
        </div>
      </div>
      <ul className="flex-1 space-y-2 overflow-y-auto bg-slate-50 p-3" aria-live="polite">
        {(messages.data ?? []).map((m) => (
          <li key={m.id} className={`flex ${m.mine ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[80%] rounded-lg p-2 text-sm ${m.mine ? 'bg-brand text-white' : 'bg-white shadow-sm'}`}>
              {!m.mine && conversation.type === 'group' && <p className="text-xs font-bold text-brand">{m.sender.name}</p>}
              {m.has_image && <ChatImage messageId={m.id} />}
              {m.body && <p className="whitespace-pre-line break-words">{m.body}</p>}
              <p className={`mt-1 flex items-center gap-2 text-xs ${m.mine ? 'text-white/80' : 'text-slate-400'}`} dir="ltr">
                <span>{time(m.created_at)}</span>{ticks(m)}
                {m.mine && <button type="button" className="underline" onClick={() => remove.mutate(m.id)}>{t('admin.actions.delete')}</button>}
              </p>
            </div>
          </li>
        ))}
        <li ref={end} aria-hidden="true" />
      </ul>
      {conversation.can_post ? (
        <form className="space-y-2 border-t p-3" onSubmit={(e) => { e.preventDefault(); if (text.trim() || photo) send.mutate() }}>
          {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
          {photo && <p className="text-xs text-slate-600">📷 {photo.name} <button type="button" className="text-red-700 underline" onClick={() => setPhoto(null)}>{t('homework.removeImage')}</button></p>}
          <div className="flex items-end gap-2">
            <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" aria-label={t('chat.photo')} onChange={(e) => { setPhoto(e.target.files[0] ?? null); e.target.value = '' }} />
            <button type="button" className="rounded border px-3 py-2" aria-label={t('chat.addPhoto')} title={t('chat.addPhoto')} onClick={() => input.current.click()}>📷</button>
            <textarea rows={1} aria-label={t('chat.message')} placeholder={t('chat.message')} className="min-w-0 flex-1 rounded border p-2" value={text} onChange={(e) => setText(e.target.value)} />
            <button type="submit" className="rounded bg-brand px-4 py-2 text-white disabled:opacity-50" disabled={send.isPending}>{t('chat.send')}</button>
          </div>
        </form>
      ) : <p className="border-t p-3 text-sm text-slate-500">{t('chat.staffOnly')}</p>}
    </div>
  )
}
