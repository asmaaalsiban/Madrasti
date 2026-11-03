import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CalendarClock, Pencil, Plus, Trash2 } from 'lucide-react'
import { api } from '../../api/client'
import { DateInput } from '../../components/shared/DateInput'
import ConfirmDialog from '../../components/shared/ConfirmDialog'
import Modal from '../../components/shared/Modal'
import { useAuth } from '../Auth/AuthProvider'
import { uploadAttachments } from '../../api/attachments'
import { AttachmentList, AttachmentsField, LiveAttachments } from '../../components/shared/Attachments'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const STATUSES = ['todo', 'doing', 'done']
const PRIORITY_STYLE = { low: 'bg-slate-100 text-slate-600', normal: 'bg-sky-tint text-deep-harbor', high: 'bg-red-100 text-red-700' }
const STATUS_STYLE = { todo: 'bg-slate-100 text-slate-700', doing: 'bg-amber-100 text-amber-800', done: 'bg-green-100 text-green-800' }

function Badges({ task }) {
  const { t } = useTranslation()
  return (
    <span className="flex flex-wrap items-center gap-2 text-xs">
      <span className={`rounded-full px-2 py-0.5 ${PRIORITY_STYLE[task.priority]}`}>{t(`tasks.priorities.${task.priority}`)}</span>
      {task.due_on && <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 ${task.overdue ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-700'}`}><CalendarClock size={12} aria-hidden /><bdi dir="ltr">{task.due_on}</bdi>{task.overdue ? ` · ${t('tasks.overdue')}` : ''}</span>}
    </span>
  )
}

function MyTask({ task }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [note, setNote] = useState(task.my_note ?? '')
  const [error, setError] = useState('')
  const move = useMutation({
    mutationFn: (status) => api.put(`/tasks/${task.id}/status`, { status, note: note || null }),
    onSuccess: () => client.invalidateQueries({ queryKey: ['tasks'] }),
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  return (
    <article className={`space-y-3 rounded-lg border bg-white p-4 ${task.my_status === 'done' ? 'opacity-70' : ''}`}>
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="text-lg font-extrabold">{task.title}</h3>
          <p className="text-sm text-slate-600">{t('tasks.from', { name: task.created_by.name })}</p>
        </div>
        <Badges task={task} />
      </header>
      {task.description && <p className="whitespace-pre-line text-sm">{task.description}</p>}
      <AttachmentList items={task.attachments} />
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      <LiveAttachments ownerType="task_part" ownerId={task.my_part_id} items={task.assignees.find((a) => a.id === task.my_part_id)?.attachments ?? []} label={t('tasks.handIn')} />
      <label className="block text-sm">{t('tasks.note')}<input className="mt-1 w-full rounded border p-2" value={note} onChange={(e) => setNote(e.target.value)} /></label>
      <div className="flex flex-wrap gap-2" role="group" aria-label={t('tasks.status')}>
        {STATUSES.map((s) => (
          <button key={s} type="button" aria-pressed={task.my_status === s} disabled={move.isPending} onClick={() => move.mutate(s)}
            className={`rounded-full border px-4 py-1.5 text-sm ${task.my_status === s ? `${STATUS_STYLE[s]} font-bold` : 'bg-white'}`}>{t(`tasks.statuses.${s}`)}</button>
        ))}
      </div>
    </article>
  )
}

function TaskDialog({ task, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const people = useQuery({ queryKey: ['tasks', 'people'], queryFn: async () => (await api.get('/tasks/people')).data.data })
  const [form, setForm] = useState({ title: task?.title ?? '', description: task?.description ?? '', due_on: task?.due_on ?? '', priority: task?.priority ?? 'normal', user_ids: task?.assignees.map((a) => a.user_id) ?? [] })
  const [error, setError] = useState('')
  const [files, setFiles] = useState([])
  const [dropped, setDropped] = useState([])
  const save = useMutation({
    mutationFn: async () => {
      const body = { ...form, due_on: form.due_on || null, description: form.description || null }
      const r = task ? await api.put(`/tasks/${task.id}`, body) : await api.post('/tasks', body)
      await Promise.all(dropped.map((id) => api.delete(`/attachments/${id}`)))
      await uploadAttachments('task', r.data.data.id, files)
      return r
    },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['tasks'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const toggle = (id) => setForm({ ...form, user_ids: form.user_ids.includes(id) ? form.user_ids.filter((x) => x !== id) : [...form.user_ids, id] })
  const [search, setSearch] = useState('')
  const [type, setType] = useState('')
  const everybody = people.data ?? []
  const types = [...new Set(everybody.map((p) => p.staff_type).filter(Boolean))]
  const q = search.trim().toLowerCase()
  // Search and the job filter narrow the list; "select all" then means everybody who is shown.
  const all = everybody.filter((p) => (!type || p.staff_type === type) && (!q || p.name.toLowerCase().includes(q)))
  const everyone = all.length > 0 && all.every((p) => form.user_ids.includes(p.id))
  const toggleShown = () => setForm({ ...form, user_ids: everyone ? form.user_ids.filter((id) => !all.some((p) => p.id === id)) : [...new Set([...form.user_ids, ...all.map((p) => p.id)])] })
  return (
    <Modal open size="lg" title={task ? t('tasks.edit') : t('tasks.new')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('tasks.what')}<input required className="mt-1 w-full rounded border p-2" value={form.title} onChange={set('title')} /></label>
        <label className="block text-sm">{t('tasks.details')}<textarea rows={3} className="mt-1 w-full rounded border p-2" value={form.description} onChange={set('description')} /></label>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{t('tasks.dueOn')}<DateInput className="mt-1 w-full rounded border p-2" value={form.due_on} onChange={set('due_on')} /></label>
          <label className="block text-sm">{t('tasks.priority')}<select className="mt-1 w-full rounded border p-2" value={form.priority} onChange={set('priority')}>{['low', 'normal', 'high'].map((p) => <option key={p} value={p}>{t(`tasks.priorities.${p}`)}</option>)}</select></label>
        </div>
        <AttachmentsField files={files} onChange={setFiles} saved={(task?.attachments ?? []).filter((a) => !dropped.includes(a.id))} onRemoveSaved={(a) => setDropped([...dropped, a.id])} />
        <fieldset className="text-sm">
          <legend className="mb-1 flex w-full items-center justify-between">{t('tasks.assignTo')}
            <button type="button" className="text-brand" onClick={toggleShown}>{everyone ? t('tasks.clearAll') : t('tasks.selectAll')}</button>
          </legend>
          <div className="mb-2 flex flex-wrap items-center gap-2">
            <input type="search" aria-label={t('admin.actions.search')} placeholder={t('admin.actions.search')} className="min-w-0 flex-1 rounded border p-1.5" value={search} onChange={(e) => setSearch(e.target.value)} />
            <select aria-label={t('tasks.filterByJob')} className="rounded border p-1.5" value={type} onChange={(e) => setType(e.target.value)}>
              <option value="">{t('tasks.allJobs')}</option>
              {types.map((k) => <option key={k} value={k}>{t(`staff.types.${k}`)}</option>)}
            </select>
            <span className="text-xs text-slate-500">{t('tasks.chosenCount', { count: form.user_ids.length })}</span>
          </div>
          {people.isLoading ? <p className="text-slate-500">{t('common.loading')}</p> : everybody.length === 0 ? <p className="text-slate-500">{t('tasks.noPeople')}</p> : all.length === 0 ? <p className="text-slate-500">{t('tasks.noMatch')}</p> : (
            <div className="flex max-h-48 flex-wrap gap-2 overflow-y-auto">
              {all.map((p) => (
                <label key={p.id} className={`flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1 ${form.user_ids.includes(p.id) ? 'border-brand bg-sky-tint font-bold' : ''}`}>
                  <input type="checkbox" className="sr-only" checked={form.user_ids.includes(p.id)} onChange={() => toggle(p.id)} /><bdi>{p.name}</bdi>{p.staff_type && <span className="text-xs font-normal text-slate-500">{t(`staff.types.${p.staff_type}`)}</span>}
                </label>
              ))}
            </div>
          )}
        </fieldset>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending || form.user_ids.length === 0} className="rounded bg-brand px-5 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

function GivenTask({ task, onEdit, onDelete }) {
  const { t } = useTranslation()
  const pct = task.total ? Math.round((task.done_count / task.total) * 100) : 0
  return (
    <article className="space-y-3 rounded-lg border bg-white p-4">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="text-lg font-extrabold">{task.title}</h3>
          <p className="text-sm text-slate-600">{t('tasks.by', { name: task.created_by.name })}</p>
        </div>
        <Badges task={task} />
      </header>
      {task.description && <p className="whitespace-pre-line text-sm">{task.description}</p>}
      <AttachmentList items={task.attachments} />
      <div>
        <p className="mb-1 text-sm">{t('tasks.progress', { done: task.done_count, total: task.total })}</p>
        <div className="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}><div className="h-full bg-brand" style={{ width: `${pct}%` }} /></div>
      </div>
      <ul className="flex flex-wrap gap-2 text-xs">
        {task.assignees.map((a) => (
          <li key={a.user_id} title={a.note ?? ''} className={`rounded-full px-3 py-1 ${STATUS_STYLE[a.status]}`}><bdi>{a.name}</bdi> · {t(`tasks.statuses.${a.status}`)}{a.note ? ` — ${a.note}` : ''}</li>
        ))}
      </ul>
      {task.assignees.filter((a) => a.attachments?.length > 0).map((a) => (
        <div key={a.user_id} className="space-y-1 rounded border bg-slate-50 p-2">
          <p className="text-xs font-bold"><bdi>{a.name}</bdi> — {t('tasks.handIn')}</p>
          <AttachmentList items={a.attachments} />
        </div>
      ))}
      {task.can_edit && (
        <span className="flex gap-1">
          <button type="button" aria-label={t('admin.actions.edit')} className="rounded border p-1.5" onClick={() => onEdit(task)}><Pencil size={16} aria-hidden /></button>
          <button type="button" aria-label={t('admin.actions.delete')} className="rounded border border-red-300 p-1.5 text-red-700" onClick={() => onDelete(task)}><Trash2 size={16} aria-hidden /></button>
        </span>
      )}
    </article>
  )
}

// Tasks for staff: every teacher sees the tasks given to them; management and heads of department also give tasks and follow them.
export default function TasksPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const client = useQueryClient()
  const canGive = user?.role === 'admin' || user?.staff_type === 'head_of_department'
  const canReceive = user?.role !== 'admin'
  const [tab, setTab] = useState(canReceive ? 'mine' : 'given')
  const [dialog, setDialog] = useState(null)
  const [del, setDel] = useState(null)
  const mine = useQuery({ queryKey: ['tasks', 'mine'], enabled: canReceive, queryFn: async () => (await api.get('/tasks/mine')).data })
  const given = useQuery({ queryKey: ['tasks', 'given'], enabled: canGive, queryFn: async () => (await api.get('/tasks/given')).data.data })
  const remove = useMutation({ mutationFn: (task) => api.delete(`/tasks/${task.id}`), onSuccess: () => { client.invalidateQueries({ queryKey: ['tasks'] }); setDel(null) } })
  const tabs = [...(canReceive ? ['mine'] : []), ...(canGive ? ['given'] : [])]
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('tasks.title')}</h1>
      <div className="flex flex-wrap items-center gap-2">
        {tabs.length > 1 && tabs.map((k) => (
          <button key={k} type="button" role="tab" aria-selected={tab === k} className={`rounded-full border px-4 py-1.5 text-sm ${tab === k ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => setTab(k)}>
            {t(`tasks.tabs.${k}`)}{k === 'mine' && mine.data?.open > 0 ? ` (${mine.data.open})` : ''}
          </button>
        ))}
        {canGive && <button type="button" className="ms-auto inline-flex items-center gap-2 rounded bg-brand px-4 py-2 text-white" onClick={() => setDialog({})}><Plus size={16} aria-hidden />{t('tasks.new')}</button>}
      </div>

      {tab === 'mine' && (
        <div className="space-y-3">
          {mine.isLoading ? <p className="text-slate-500">{t('common.loading')}</p> : (mine.data?.data ?? []).length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('tasks.noneMine')}</p> : mine.data.data.map((task) => <MyTask key={`${task.id}-${task.my_status}`} task={task} />)}
        </div>
      )}
      {tab === 'given' && (
        <div className="space-y-3">
          {given.isLoading ? <p className="text-slate-500">{t('common.loading')}</p> : (given.data ?? []).length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('tasks.noneGiven')}</p> : given.data.map((task) => <GivenTask key={task.id} task={task} onEdit={(x) => setDialog({ task: x })} onDelete={setDel} />)}
        </div>
      )}

      {dialog && <TaskDialog task={dialog.task} onClose={() => setDialog(null)} />}
      <ConfirmDialog open={Boolean(del)} message={t('tasks.deleteQuestion', { title: del?.title })} onConfirm={() => remove.mutate(del)} onCancel={() => setDel(null)} busy={remove.isPending} />
    </section>
  )
}
