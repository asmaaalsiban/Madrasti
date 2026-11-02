import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import PlanCard from '../components/PlanCard'
import { DateInput } from '../../../components/shared/DateInput'

const today = () => new Date().toISOString().slice(0, 10)
const EMPTY = { pair: '', date: today(), title: '', objectives: '', content: '', shared: true, fromStatus: null }

// Teacher: write the plan of a week as a draft, submit it to the admin, fix it if it is rejected.
export default function LessonPlansPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState(EMPTY)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const [removing, setRemoving] = useState(null)
  const subject = (s) => s[`name_${i18n.language}`] || s.name_ar

  const options = useQuery({ queryKey: ['lesson-plans', 'options'], queryFn: async () => (await api.get('/grading/options')).data.data.assignments })
  const mine = useQuery({ queryKey: ['lesson-plans', 'mine'], queryFn: async () => (await api.get('/lesson-plans')).data.data })

  const first = options.data?.[0]
  const pair = form.pair || (first ? `${first.classroom.id}-${first.subject.id}` : '')
  const set = (key) => (e) => { setForm({ ...form, [key]: e.target.value }); setSaved(false) }
  const refresh = () => client.invalidateQueries({ queryKey: ['lesson-plans'] })
  const fail = (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error'))

  const save = useMutation({
    mutationFn: () => {
      const [classroom_id, subject_id] = pair.split('-').map(Number)
      return api.put('/lesson-plans', { classroom_id, subject_id, date: form.date, title: form.title, objectives: form.objectives || null, content: form.content || null, shared_with_parents: form.shared })
    },
    onSuccess: () => { setError(''); setSaved(true); setForm((f) => ({ ...f, fromStatus: 'draft' })); refresh() },
    onError: fail,
  })
  const withdraw = useMutation({ mutationFn: (id) => api.post(`/lesson-plans/${id}/withdraw`), onSuccess: () => { setError(''); refresh() }, onError: fail })
  const submit = useMutation({ mutationFn: (id) => api.post(`/lesson-plans/${id}/submit`), onSuccess: () => { setError(''); refresh() }, onError: fail })
  const remove = useMutation({ mutationFn: (id) => api.delete(`/lesson-plans/${id}`), onSuccess: () => { setRemoving(null); refresh() }, onError: fail })

  const edit = (p) => { setError(''); setSaved(false); setForm({ pair: `${p.classroom.id}-${p.subject.id}`, date: p.week_starts_on, title: p.title, objectives: p.objectives ?? '', content: p.content ?? '', shared: p.shared_with_parents, fromStatus: p.status }) }

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('lessonPlans.title')}</h1>
      <p className="text-sm text-slate-600">{t('lessonPlans.help')}</p>
      <form className="space-y-3 rounded-lg bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <div className="flex flex-wrap gap-3">
          <label className="text-sm">{t('homework.classAndSubject')}
            <select className="mt-1 block rounded border p-2" value={pair} onChange={set('pair')}>
              {(options.data ?? []).map((a) => <option key={`${a.classroom.id}-${a.subject.id}`} value={`${a.classroom.id}-${a.subject.id}`}>{`${a.classroom.name} · ${subject(a.subject)}`}</option>)}
            </select>
          </label>
          <label className="text-sm">{t('lessonPlans.weekOf')}<DateInput className="mt-1 block rounded border p-2" value={form.date} onChange={set('date')} /></label>
        </div>
        <label className="block text-sm">{t('lessonPlans.planTitle')}<input required className="mt-1 w-full rounded border p-2" value={form.title} onChange={set('title')} /></label>
        <label className="block text-sm">{t('lessonPlans.objectives')}<textarea rows={3} className="mt-1 w-full rounded border p-2" value={form.objectives} onChange={set('objectives')} /></label>
        <label className="block text-sm">{t('lessonPlans.content')}<textarea rows={6} className="mt-1 w-full rounded border p-2" value={form.content} onChange={set('content')} /></label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.shared} onChange={(e) => { setForm({ ...form, shared: e.target.checked }); setSaved(false) }} />{t('lessonPlans.shareWithParents')}</label>
        {['submitted', 'approved'].includes(form.fromStatus) && <p role="note" className="rounded bg-amber-50 p-2 text-sm text-amber-800">{t(`lessonPlans.editWarning.${form.fromStatus}`)}</p>}
        <div className="flex items-center gap-3">
          <button type="submit" className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50" disabled={!pair || save.isPending}>{t('lessonPlans.saveDraft')}</button>
          {saved && <span role="status" className="text-sm text-green-700">{t('lessonPlans.saved')}</span>}
        </div>
      </form>

      <h2 className="font-bold">{t('lessonPlans.mine')}</h2>
      {mine.isSuccess && mine.data.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('lessonPlans.noneWritten')}</p>}
      <ul className="space-y-3">
        {(mine.data ?? []).map((p) => (
          <PlanCard key={p.id} plan={p}>
            <div className="flex flex-wrap gap-2 pt-1">
              <button type="button" className="rounded border px-3 py-1 text-sm" onClick={() => edit(p)}>{t('admin.actions.edit')}</button>
              {['draft', 'rejected'].includes(p.status) && <button type="button" className="rounded bg-brand px-3 py-1 text-sm text-white" onClick={() => submit.mutate(p.id)}>{t('lessonPlans.submit')}</button>}
              {p.status === 'submitted' && <button type="button" className="rounded border px-3 py-1 text-sm" onClick={() => withdraw.mutate(p.id)}>{t('lessonPlans.withdraw')}</button>}
              <button type="button" className="rounded border border-red-300 px-3 py-1 text-sm text-red-700" onClick={() => setRemoving(p)}>{t('admin.actions.delete')}</button>
            </div>
          </PlanCard>
        ))}
      </ul>
      <ConfirmDialog open={Boolean(removing)} message={t('lessonPlans.confirmDelete', { title: removing?.title })} onConfirm={() => remove.mutate(removing.id)} onCancel={() => setRemoving(null)} />
    </section>
  )
}
