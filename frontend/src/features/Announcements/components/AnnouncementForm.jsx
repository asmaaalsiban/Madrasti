import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { DateInput } from '../../../components/shared/DateInput'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const ROLES = ['teacher', 'student', 'parent', 'admin']

// Write or edit an announcement. The admin picks everyone / a role / a section; a teacher only one of their sections.
export default function AnnouncementForm({ announcement, options, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({
    title: announcement?.title ?? '', body: announcement?.body ?? '',
    audience: announcement?.audience ?? (options.can_publish_all ? 'all' : 'classroom'),
    audience_role: announcement?.audience_role ?? 'parent', classroom_id: String(announcement?.classroom?.id ?? options.classrooms[0]?.id ?? ''),
    is_pinned: announcement?.is_pinned ?? false, expires_at: announcement?.expires_at?.slice(0, 10) ?? '',
  })
  const [error, setError] = useState('')
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  const save = useMutation({
    mutationFn: () => {
      const body = {
        title: form.title, body: form.body, audience: form.audience, is_pinned: form.is_pinned, expires_at: form.expires_at || null,
        ...(form.audience === 'role' ? { audience_role: form.audience_role } : {}), ...(form.audience === 'classroom' ? { classroom_id: Number(form.classroom_id) } : {}),
      }
      return announcement ? api.put(`/announcements/${announcement.id}`, body) : api.post('/announcements', body)
    },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['announcements'] }); onClose() },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')),
  })

  return (
    <Modal open size="lg" title={announcement ? t('announcements.edit') : t('announcements.add')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {options.can_publish_all && (
          <label className="block text-sm">{t('announcements.audience')}
            <select className="mt-1 block w-full rounded border p-2" value={form.audience} onChange={set('audience')}>
              <option value="all">{t('announcements.audiences.all')}</option>
              <option value="role">{t('announcements.audiences.role')}</option>
              <option value="classroom">{t('announcements.audiences.classroom')}</option>
            </select>
          </label>
        )}
        {form.audience === 'role' && (
          <label className="block text-sm">{t('announcements.role')}
            <select className="mt-1 block w-full rounded border p-2" value={form.audience_role} onChange={set('audience_role')}>
              {ROLES.map((r) => <option key={r} value={r}>{t(`roles.${r}`)}</option>)}
            </select>
          </label>
        )}
        {form.audience === 'classroom' && (
          <label className="block text-sm">{t('academic.fields.classroom')}
            <select required className="mt-1 block w-full rounded border p-2" value={form.classroom_id} onChange={set('classroom_id')}>
              {options.classrooms.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </label>
        )}
        <label className="block text-sm">{t('announcements.fields.title')}<input required className="mt-1 w-full rounded border p-2" value={form.title} onChange={set('title')} /></label>
        <label className="block text-sm">{t('announcements.fields.body')}<textarea required rows={5} className="mt-1 w-full rounded border p-2" value={form.body} onChange={set('body')} /></label>
        <div className="flex flex-wrap items-center gap-4">
          <label className="text-sm">{t('announcements.expiresAt')}<DateInput className="mt-1 block rounded border p-2" value={form.expires_at} onChange={set('expires_at')} /></label>
          {options.can_publish_all && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_pinned} onChange={(e) => setForm({ ...form, is_pinned: e.target.checked })} />{t('announcements.pin')}</label>}
        </div>
        <div className="flex justify-end gap-2">
          <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</InteractiveHoverButton>
          <InteractiveHoverButton type="submit" className="px-4 py-1.5 disabled:opacity-50" disabled={save.isPending}>{t('announcements.publish')}</InteractiveHoverButton>
        </div>
      </form>
    </Modal>
  )
}
