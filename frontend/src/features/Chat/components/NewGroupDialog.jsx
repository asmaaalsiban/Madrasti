import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// A group for a section: its students and teachers, optionally the parents; optionally only the teachers can write.
export default function NewGroupDialog({ onClose, onOpened }) {
  const { t } = useTranslation()
  const options = useQuery({ queryKey: ['announcements', 'options'], queryFn: async () => (await api.get('/announcements/options')).data.data })
  const [form, setForm] = useState({ classroom_id: '', title: '', include_parents: false, members_can_post: true })
  const [error, setError] = useState('')
  const classroomId = form.classroom_id || String(options.data?.classrooms?.[0]?.id ?? '')

  const create = useMutation({
    mutationFn: () => api.post('/chat/groups', { classroom_id: Number(classroomId), title: form.title || null, include_parents: form.include_parents, members_can_post: form.members_can_post }).then((r) => r.data.data),
    onSuccess: (c) => onOpened(c.id),
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')),
  })

  return (
    <Modal open title={t('chat.newGroup')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); create.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('academic.fields.classroom')}
          <select required className="mt-1 block w-full rounded border p-2" value={classroomId} onChange={(e) => setForm({ ...form, classroom_id: e.target.value })}>
            {(options.data?.classrooms ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </label>
        <label className="block text-sm">{t('chat.groupTitle')}<input className="mt-1 w-full rounded border p-2" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.include_parents} onChange={(e) => setForm({ ...form, include_parents: e.target.checked })} />{t('chat.includeParents')}</label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!form.members_can_post} onChange={(e) => setForm({ ...form, members_can_post: !e.target.checked })} />{t('chat.teachersOnly')}</label>
        <p className="text-xs text-slate-500">{t('chat.groupHelp')}</p>
        <div className="flex justify-end gap-2">
          <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</InteractiveHoverButton>
          <InteractiveHoverButton type="submit" className="px-4 py-1.5 disabled:opacity-50" disabled={create.isPending || !classroomId}>{t('chat.createGroup')}</InteractiveHoverButton>
        </div>
      </form>
    </Modal>
  )
}
