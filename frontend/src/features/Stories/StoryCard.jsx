import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Avatar from '../../components/shared/Avatar'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Heart, MessageCircle, Pin, PinOff, Trash2, MessageCircleOff, MessageCirclePlus, Megaphone } from 'lucide-react'
import { api } from '../../api/client'
import AuthImage from '../../components/shared/AuthImage'
import ConfirmDialog from '../../components/shared/ConfirmDialog'
import Modal from '../../components/shared/Modal'

const ago = (iso, language) => {
  const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000)
  const f = new Intl.RelativeTimeFormat(language === 'ar' ? 'ar' : 'en', { numeric: 'auto' })
  for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]]) if (Math.abs(seconds) >= size) return f.format(Math.round(seconds / size), unit)
  return f.format(0, 'second')
}

function Comments({ story }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [text, setText] = useState('')
  const [error, setError] = useState('')
  const { data } = useQuery({ queryKey: ['stories', story.id, 'comments'], queryFn: async () => (await api.get(`/stories/${story.id}/comments`)).data.data })
  const refresh = () => { client.invalidateQueries({ queryKey: ['stories'] }) }
  const add = useMutation({
    mutationFn: () => api.post(`/stories/${story.id}/comments`, { body: text }),
    onSuccess: () => { setText(''); setError(''); refresh() },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')),
  })
  const drop = useMutation({ mutationFn: (id) => api.delete(`/stories/comments/${id}`), onSuccess: refresh })
  return (
    <div className="space-y-3 border-t pt-3">
      <ul className="space-y-2">
        {(data ?? []).map((c) => (
          <li key={c.id} className="flex items-start gap-2 text-sm">
            <span aria-hidden className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-sky-tint text-xs font-bold text-deep-harbor">{c.user.name.trim().charAt(0)}</span>
            <span className="min-w-0 flex-1 rounded-2xl bg-slate-50 px-3 py-1.5"><b className="me-2 text-deep-harbor">{c.user.name}</b><span className="text-xs text-slate-500">{ago(c.created_at, i18n.language)}</span><span className="block break-words">{c.body}</span></span>
            {c.can_delete && <button type="button" aria-label={t('admin.actions.delete')} className="text-slate-400 hover:text-red-600" onClick={() => drop.mutate(c.id)}><Trash2 size={15} /></button>}
          </li>
        ))}
      </ul>
      {story.comments_enabled ? (
        <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); if (text.trim()) add.mutate() }}>
          <input aria-label={t('stories.writeComment')} maxLength={500} className="min-w-0 flex-1 rounded border p-2 text-sm" placeholder={t('stories.writeComment')} value={text} onChange={(e) => setText(e.target.value)} />
          <button type="submit" disabled={add.isPending || !text.trim()} className="rounded bg-brand px-4 py-1.5 text-sm text-white disabled:opacity-50">{t('stories.send')}</button>
        </form>
      ) : <p className="text-xs text-slate-500">{t('stories.commentsClosed')}</p>}
      {error && <p role="alert" className="text-xs text-red-700">{error}</p>}
    </div>
  )
}

function Photos({ ids, onOpen }) {
  const { t } = useTranslation()
  if (ids.length === 0) return null
  const shown = ids.slice(0, 4)
  const layout = shown.length === 1 ? 'grid-cols-1' : 'grid-cols-2'
  return (
    <div className={`grid gap-1 overflow-hidden rounded-2xl ${layout}`}>
      {shown.map((id, i) => (
        <button key={id} type="button" className={`relative block overflow-hidden ${shown.length === 3 && i === 0 ? 'col-span-2' : ''}`} aria-label={t('stories.openPhoto', { number: i + 1 })} onClick={() => onOpen(i)}>
          <AuthImage path={`/stories/media/${id}`} alt="" className={`w-full object-cover ${shown.length === 1 ? 'max-h-96' : 'h-44'}`} />
          {i === 3 && ids.length > 4 && <span className="absolute inset-0 grid place-items-center bg-black/50 text-2xl font-bold text-white">+{ids.length - 4}</span>}
        </button>
      ))}
    </div>
  )
}

// One post of the feed: who and where, the text, the photos, a heart, the comments, and (for the author or the principal) pin / close comments / delete.
export default function StoryCard({ story }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [open, setOpen] = useState(false)
  const [viewer, setViewer] = useState(null)
  const [askDelete, setAskDelete] = useState(false)
  const refresh = () => client.invalidateQueries({ queryKey: ['stories'] })
  const heart = useMutation({ mutationFn: () => api.post(`/stories/${story.id}/react`), onSuccess: refresh })
  const change = useMutation({ mutationFn: (patch) => api.put(`/stories/${story.id}`, patch), onSuccess: refresh })
  const drop = useMutation({ mutationFn: () => api.delete(`/stories/${story.id}`), onSuccess: () => { setAskDelete(false); refresh() } })
  const where = story.audience === 'school' ? t('stories.wholeSchool') : story.target?.[`name_${i18n.language}`] || story.target?.name_ar
  const role = story.author.staff_type ? t(`staff.types.${story.author.staff_type}`) : t(`roles.${story.author.role}`)
  return (
    <article className={`space-y-3 rounded-lg border bg-snow p-4 ${story.is_pinned ? 'ring-2 ring-sky-tint' : ''}`}>
      <header className="flex items-center gap-3">
        <Avatar user={story.author} size={44} />
        <div className="min-w-0 flex-1">
          <p className="truncate font-bold text-deep-harbor">{story.author.name}</p>
          <p className="text-xs text-slate-500">{role} · {ago(story.created_at, i18n.language)}</p>
        </div>
        <span className="flex items-center gap-1 rounded-full bg-sky-tint px-3 py-0.5 text-xs font-semibold text-deep-harbor">{story.audience === 'school' && <Megaphone size={13} aria-hidden />}<bdi dir="ltr">{where}</bdi></span>
        {story.is_pinned && <Pin aria-label={t('stories.pinned')} size={16} className="text-brand" />}
      </header>
      {story.body && <p className="whitespace-pre-wrap break-words text-ink">{story.body}</p>}
      <Photos ids={story.media} onOpen={setViewer} />
      <footer className="flex flex-wrap items-center gap-2 text-sm">
        <button type="button" aria-pressed={story.liked} disabled={heart.isPending} className={`flex items-center gap-1.5 rounded-full border px-3 py-1 ${story.liked ? 'border-red-200 bg-red-50 text-red-600' : ''}`} onClick={() => heart.mutate()}>
          <Heart size={17} className={story.liked ? 'fill-red-500' : ''} aria-hidden /><span className="sr-only">{t('stories.like')}</span><b>{story.reactions_count}</b>
        </button>
        <button type="button" aria-expanded={open} className="flex items-center gap-1.5 rounded-full border px-3 py-1" onClick={() => setOpen(!open)}>
          <MessageCircle size={17} aria-hidden />{t('stories.comments')} <b>{story.comments_count}</b>
        </button>
        {story.can_manage && (
          <span className="ms-auto flex items-center gap-1">
            <button type="button" title={story.is_pinned ? t('stories.unpin') : t('stories.pin')} aria-label={story.is_pinned ? t('stories.unpin') : t('stories.pin')} className="rounded-full border p-1.5" onClick={() => change.mutate({ is_pinned: !story.is_pinned })}>{story.is_pinned ? <PinOff size={16} /> : <Pin size={16} />}</button>
            <button type="button" title={story.comments_enabled ? t('stories.closeComments') : t('stories.openComments')} aria-label={story.comments_enabled ? t('stories.closeComments') : t('stories.openComments')} className="rounded-full border p-1.5" onClick={() => change.mutate({ comments_enabled: !story.comments_enabled })}>{story.comments_enabled ? <MessageCircleOff size={16} /> : <MessageCirclePlus size={16} />}</button>
            <button type="button" title={t('admin.actions.delete')} aria-label={t('admin.actions.delete')} className="rounded-full border border-red-200 p-1.5 text-red-600" onClick={() => setAskDelete(true)}><Trash2 size={16} /></button>
          </span>
        )}
      </footer>
      {open && <Comments story={story} />}
      <ConfirmDialog open={askDelete} message={t('stories.deleteTitle')} description={t('stories.deleteHelp')} busy={drop.isPending} onConfirm={() => drop.mutate()} onCancel={() => setAskDelete(false)} />
      {viewer !== null && (
        <Modal open size="lg" title={t('stories.photos')} onClose={() => setViewer(null)}>
          <div className="space-y-3">
            <AuthImage path={`/stories/media/${story.media[viewer]}`} alt="" className="mx-auto max-h-[70vh] rounded-lg" />
            <div className="flex items-center justify-between text-sm">
              <button type="button" disabled={viewer === 0} className="rounded border px-3 py-1 disabled:opacity-40" onClick={() => setViewer(viewer - 1)}>{t('admin.actions.prev')}</button>
              <span dir="ltr">{viewer + 1} / {story.media.length}</span>
              <button type="button" disabled={viewer >= story.media.length - 1} className="rounded border px-3 py-1 disabled:opacity-40" onClick={() => setViewer(viewer + 1)}>{t('admin.actions.next')}</button>
            </div>
          </div>
        </Modal>
      )}
    </article>
  )
}
