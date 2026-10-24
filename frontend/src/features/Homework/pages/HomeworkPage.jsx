import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import FormField from '../../../components/shared/FormField'
import Modal from '../../../components/shared/Modal'
import HomeworkImage from '../components/HomeworkImage'

const EMPTY = { pair: '', title: '', description: '', due_on: '', image: null, removeImage: false }

// Teacher (own homework) and admin (all): send homework to a section, edit or delete it.
export default function HomeworkPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState(null) // null = closed; { id?, ...EMPTY }
  const [error, setError] = useState('')
  const [removing, setRemoving] = useState(null)
  const subject = (s) => s[`name_${i18n.language}`] || s.name_ar

  const list = useQuery({ queryKey: ['homework', 'staff'], queryFn: async () => (await api.get('/homework')).data.data })
  const options = useQuery({ queryKey: ['homework', 'options'], queryFn: async () => (await api.get('/grading/options')).data.data.assignments })

  const done = () => { client.invalidateQueries({ queryKey: ['homework'] }); setForm(null); setError(''); setRemoving(null) }
  const save = useMutation({
    mutationFn: () => {
      const [classroom_id, subject_id] = form.pair.split('-').map(Number)
      const body = { classroom_id, subject_id, title: form.title, description: form.description || null, due_on: form.due_on }
      return (form.id ? api.put(`/homework/${form.id}`, body) : api.post('/homework', body)).then(async (res) => {
        const id = res.data.data.id
        if (form.image) {
          const data = new FormData()
          data.append('image', form.image)
          await api.post(`/homework/${id}/image`, data)
        } else if (form.removeImage) {
          await api.delete(`/homework/${id}/image`)
        }
        return res
      })
    },
    onSuccess: done,
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || t('admin.actions.error')),
  })
  const remove = useMutation({ mutationFn: (id) => api.delete(`/homework/${id}`), onSuccess: done })

  const edit = (h) => setForm({ id: h.id, pair: `${h.classroom.id}-${h.subject.id}`, title: h.title, description: h.description ?? '', due_on: h.due_on, hasImage: h.has_image, image: null, removeImage: false })
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('homework.title')}</h1>
        <button type="button" className="rounded bg-brand px-4 py-1.5 text-white" onClick={() => { setError(''); setForm({ ...EMPTY }) }}>{t('homework.add')}</button>
      </div>

      {list.isSuccess && list.data.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('homework.noneSent')}</p>}
      <ul className="space-y-3">
        {(list.data ?? []).map((h) => (
          <li key={h.id} className="space-y-1 rounded-lg border bg-white p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="font-bold">{h.title}</span>
              <span className="text-sm text-slate-600">{h.classroom.name} · {subject(h.subject)}</span>
            </div>
            {h.description && <p className="whitespace-pre-line text-sm text-slate-700">{h.description}</p>}
            {h.has_image && <HomeworkImage id={h.id} />}
            <p className="text-xs text-slate-500">{t('homework.dueOn', { date: h.due_on })} · {h.teacher.name}</p>
            <div className="flex gap-2 pt-1">
              <button type="button" className="rounded border px-3 py-1 text-sm" onClick={() => edit(h)}>{t('admin.actions.edit')}</button>
              <button type="button" className="rounded border border-red-300 px-3 py-1 text-sm text-red-700" onClick={() => setRemoving(h)}>{t('admin.actions.delete')}</button>
            </div>
          </li>
        ))}
      </ul>

      {form && (
        <Modal open title={form.id ? t('homework.edit') : t('homework.add')} onClose={() => setForm(null)}>
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
            {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
            <FormField label={t('homework.classAndSubject')}>
              <select required className="w-full rounded border p-2" value={form.pair} onChange={set('pair')}>
                <option value="">—</option>
                {(options.data ?? []).map((a) => <option key={`${a.classroom.id}-${a.subject.id}`} value={`${a.classroom.id}-${a.subject.id}`}>{`${a.classroom.name} · ${subject(a.subject)}`}</option>)}
              </select>
            </FormField>
            <FormField label={t('homework.fields.title')}><input required className="w-full rounded border p-2" value={form.title} onChange={set('title')} /></FormField>
            <FormField label={t('homework.fields.description')}><textarea rows={4} className="w-full rounded border p-2" value={form.description} onChange={set('description')} /></FormField>
            <FormField label={t('homework.fields.dueOn')}><input required type="date" className="w-full rounded border p-2" value={form.due_on} onChange={set('due_on')} /></FormField>
            <FormField label={t('homework.fields.image')} hint={form.hasImage && !form.removeImage && !form.image ? t('homework.hasImage') : t('homework.imageHint')}>
              <input type="file" accept="image/jpeg,image/png,image/webp" className="w-full text-sm" onChange={(e) => setForm({ ...form, image: e.target.files[0] ?? null, removeImage: false })} />
            </FormField>
            {form.hasImage && !form.image && !form.removeImage && (
              <button type="button" className="text-sm text-red-700 underline" onClick={() => setForm({ ...form, removeImage: true })}>{t('homework.removeImage')}</button>
            )}
            <div className="flex justify-end gap-2">
              <button type="button" className="rounded border px-3 py-1.5" onClick={() => setForm(null)}>{t('admin.actions.cancel')}</button>
              <button type="submit" className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50" disabled={save.isPending}>{t('admin.actions.save')}</button>
            </div>
          </form>
        </Modal>
      )}
      <ConfirmDialog open={Boolean(removing)} message={t('homework.confirmDelete', { title: removing?.title })} onConfirm={() => remove.mutate(removing.id)} onCancel={() => setRemoving(null)} />
    </section>
  )
}
