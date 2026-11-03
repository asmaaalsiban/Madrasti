import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import { useAuth } from '../../Auth/AuthProvider'
import AnnouncementForm from '../components/AnnouncementForm'

// Everyone: the announcements meant for them, unread ones marked; opening one marks it read. Admin and teachers can also publish.
export default function AnnouncementsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const client = useQueryClient()
  const canPublish = ['admin', 'teacher'].includes(user.role)
  const [editing, setEditing] = useState(null) // null | {} (new) | announcement
  const [removing, setRemoving] = useState(null)
  const [open, setOpen] = useState(null)

  const list = useQuery({ queryKey: ['announcements', 'list'], queryFn: async () => (await api.get('/announcements')).data.data })
  const options = useQuery({ queryKey: ['announcements', 'options'], queryFn: async () => (await api.get('/announcements/options')).data.data, enabled: canPublish })
  const refresh = () => { client.invalidateQueries({ queryKey: ['announcements'] }); client.invalidateQueries({ queryKey: ['badges'] }) }
  const markRead = useMutation({ mutationFn: (id) => api.post(`/announcements/${id}/read`), onSuccess: refresh })
  const remove = useMutation({ mutationFn: (id) => api.delete(`/announcements/${id}`), onSuccess: () => { setRemoving(null); refresh() } })

  const toggle = (a) => {
    setOpen(open === a.id ? null : a.id)
    if (!a.read) markRead.mutate(a.id)
  }
  const target = (a) => (a.audience === 'all' ? t('announcements.audiences.all') : a.audience === 'role' ? t(`roles.${a.audience_role}`) : a.classroom?.name)

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('announcements.title')}</h1>
        {canPublish && options.data && <button type="button" className="rounded bg-brand px-4 py-1.5 text-white" onClick={() => setEditing({})}>{t('announcements.add')}</button>}
      </div>
      {list.isSuccess && list.data.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('announcements.none')}</p>}
      <ul className="space-y-3">
        {(list.data ?? []).map((a) => (
          <li key={a.id} className={`rounded-lg border bg-white p-4 ${a.read ? '' : 'border-brand'}`}>
            <button type="button" className="flex w-full flex-wrap items-center justify-between gap-2 text-start" aria-expanded={open === a.id} onClick={() => toggle(a)}>
              <span className="font-bold">{a.is_pinned && <span aria-label={t('announcements.pinned')}>📌 </span>}{a.title}</span>
              <span className="flex items-center gap-2 text-xs text-slate-500">
                {!a.read && <span className="rounded-full bg-brand px-2 py-0.5 text-white">{t('announcements.new')}</span>}
                <span dir="ltr">{a.created_at.slice(0, 10)}</span>
              </span>
            </button>
            <p className="text-xs text-slate-500">{a.author.name} · {target(a)}</p>
            {open === a.id && <p className="mt-2 whitespace-pre-line text-sm text-slate-700">{a.body}</p>}
            {a.can_edit && canPublish && (
              <div className="flex gap-2 pt-2">
                <button type="button" className="rounded border px-3 py-1 text-sm" onClick={() => setEditing(a)}>{t('admin.actions.edit')}</button>
                <button type="button" className="rounded border border-red-300 px-3 py-1 text-sm text-red-700" onClick={() => setRemoving(a)}>{t('admin.actions.delete')}</button>
              </div>
            )}
          </li>
        ))}
      </ul>
      {editing && options.data && <AnnouncementForm key={editing.id ?? 'new'} announcement={editing.id ? editing : null} options={options.data} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={Boolean(removing)} message={t('announcements.confirmDelete', { title: removing?.title })} onConfirm={() => remove.mutate(removing.id)} onCancel={() => setRemoving(null)} />
    </section>
  )
}
