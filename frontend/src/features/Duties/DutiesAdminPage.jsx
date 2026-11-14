import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { DateInput } from '../../components/shared/DateInput'
import ConfirmDialog from '../../components/shared/ConfirmDialog'
import Modal from '../../components/shared/Modal'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const DAYS = [0, 1, 2, 3, 4, 5, 6] // Sunday first

function PostDialog({ post, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ name: post?.name ?? '', starts_at: post?.starts_at ?? '07:15', ends_at: post?.ends_at ?? '07:40', staff_count: post?.staff_count ?? 1, days: post?.days ?? [], is_active: post?.is_active ?? true })
  const [error, setError] = useState('')
  const [confirming, setConfirming] = useState(false)
  const save = useMutation({
    mutationFn: () => (post ? api.put(`/duties/posts/${post.id}`, form) : api.post('/duties/posts', form)),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['duties'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const drop = useMutation({ mutationFn: () => api.delete(`/duties/posts/${post.id}`), onSuccess: () => { client.invalidateQueries({ queryKey: ['duties'] }); onClose() } })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  return (
    <Modal open title={post ? t('duties.editPost') : t('duties.newPost')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('duties.postName')}<input required className="mt-1 w-full rounded border p-2" placeholder={t('duties.postNameHint')} value={form.name} onChange={set('name')} /></label>
        <div className="grid gap-3 sm:grid-cols-3">
          <label className="block text-sm">{t('duties.from')}<input required type="time" className="mt-1 w-full rounded border p-2" value={form.starts_at} onChange={set('starts_at')} /></label>
          <label className="block text-sm">{t('duties.to')}<input required type="time" className="mt-1 w-full rounded border p-2" value={form.ends_at} onChange={set('ends_at')} /></label>
          <label className="block text-sm">{t('duties.staffCount')}<input required type="number" min="1" max="10" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.staff_count} onChange={set('staff_count')} /></label>
        </div>
        <div className="text-sm">
          <p className="mb-1 font-medium">{t('duties.days')} <span className="font-normal text-slate-500">({t('duties.daysHint')})</span></p>
          <div className="flex flex-wrap gap-2">
            {DAYS.map((d) => (
              <label key={d} className={`cursor-pointer rounded-full border px-3 py-1 ${form.days.includes(d) ? 'bg-brand text-white' : 'bg-snow'}`}>
                <input type="checkbox" className="sr-only" checked={form.days.includes(d)} onChange={() => setForm({ ...form, days: form.days.includes(d) ? form.days.filter((x) => x !== d) : [...form.days, d] })} />{t(`duties.weekdays.${d}`)}
              </label>
            ))}
          </div>
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />{t('duties.active')}</label>
        <div className="flex justify-between gap-2">
          {post ? <button type="button" className="rounded border border-red-300 px-3 py-1.5 text-red-700" onClick={() => setConfirming(true)}>{t('admin.actions.delete')}</button> : <span />}
          <span className="flex gap-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
            <InteractiveHoverButton type="submit" disabled={save.isPending} className="px-4 py-1.5 disabled:opacity-50">{t('admin.actions.save')}</InteractiveHoverButton>
          </span>
        </div>
      </form>
      <ConfirmDialog open={confirming} message={t('duties.deletePostTitle')} description={`${post?.name} — ${t('duties.deletePostConfirm')}`} onConfirm={() => drop.mutate()} onCancel={() => setConfirming(false)} busy={drop.isPending} />
    </Modal>
  )
}

// One duty: change the person or the time (the change is locked, so regenerating keeps it), or remove it; also adds a duty by hand.
function DutyDialog({ duty, posts, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const staff = useQuery({ queryKey: ['payroll', 'staff'], queryFn: async () => (await api.get('/payroll/staff')).data })
  const [form, setForm] = useState({ user_id: duty?.user.id ?? '', date: duty?.date ?? '', starts_at: duty?.starts_at ?? '', ends_at: duty?.ends_at ?? '', duty_post_id: duty?.post_id ?? '', location: duty?.location ?? '' })
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => (duty
      ? api.put(`/duties/${duty.id}`, { user_id: Number(form.user_id), date: form.date, starts_at: form.starts_at, ends_at: form.ends_at })
      : api.post('/duties', { user_id: Number(form.user_id), date: form.date, ...(form.duty_post_id ? { duty_post_id: Number(form.duty_post_id) } : { starts_at: form.starts_at, ends_at: form.ends_at, location: form.location }) })),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['duties'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const drop = useMutation({ mutationFn: () => api.delete(`/duties/${duty.id}`), onSuccess: () => { client.invalidateQueries({ queryKey: ['duties'] }); onClose() } })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const teachers = (staff.data?.data ?? []).filter((s) => ['teacher', 'head_of_department', 'subject_coordinator', 'trainee', 'substitute_teacher'].includes(s.staff_type))
  const custom = !duty && !form.duty_post_id
  return (
    <Modal open title={duty ? `${duty.location} · ${duty.date}` : t('duties.addDuty')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('duties.person')}
          <select required className="mt-1 w-full rounded border p-2" value={form.user_id} onChange={set('user_id')}><option value="">—</option>{teachers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
        </label>
        {!duty && (
          <label className="block text-sm">{t('duties.post')}
            <select className="mt-1 w-full rounded border p-2" value={form.duty_post_id} onChange={set('duty_post_id')}><option value="">{t('duties.customPost')}</option>{posts.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.starts_at}–{p.ends_at})</option>)}</select>
          </label>
        )}
        <label className="block text-sm">{t('duties.date')}<DateInput required className="mt-1 w-full rounded border p-2" value={form.date} onChange={set('date')} /></label>
        {(duty || custom) && (
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm">{t('duties.from')}<input required type="time" className="mt-1 w-full rounded border p-2" value={form.starts_at} onChange={set('starts_at')} /></label>
            <label className="block text-sm">{t('duties.to')}<input required type="time" className="mt-1 w-full rounded border p-2" value={form.ends_at} onChange={set('ends_at')} /></label>
          </div>
        )}
        {custom && <label className="block text-sm">{t('duties.location')}<input required className="mt-1 w-full rounded border p-2" value={form.location} onChange={set('location')} /></label>}
        <div className="flex justify-between gap-2">
          {duty ? <button type="button" className="rounded border border-red-300 px-3 py-1.5 text-red-700" onClick={() => drop.mutate()}>{t('admin.actions.delete')}</button> : <span />}
          <span className="flex gap-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
            <InteractiveHoverButton type="submit" disabled={save.isPending} className="px-4 py-1.5 disabled:opacity-50">{t('admin.actions.save')}</InteractiveHoverButton>
          </span>
        </div>
      </form>
    </Modal>
  )
}

// Who takes part in the duty roster: tick the teachers who do duty; the others are never put on it.
function WhoDoesDuty() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const { data } = useQuery({ queryKey: ['duties', 'staff'], queryFn: async () => (await api.get('/duties/staff')).data.data })
  if (!data) return null
  return <WhoForm key={data.map((x) => `${x.id}${x.does_duty ? 1 : 0}`).join('')} teachers={data} onSaved={() => client.invalidateQueries({ queryKey: ['duties', 'staff'] })} t={t} />
}

function WhoForm({ teachers, onSaved, t }) {
  const [picked, setPicked] = useState(teachers.filter((x) => x.does_duty).map((x) => x.id))
  const [search, setSearch] = useState('')
  const [saved, setSaved] = useState(false)
  const save = useMutation({ mutationFn: () => api.put('/duties/staff', { user_ids: picked }), onSuccess: () => { setSaved(true); onSaved() } })
  const q = search.trim().toLowerCase()
  const shown = teachers.filter((x) => !q || x.name.toLowerCase().includes(q))
  const change = (ids) => { setSaved(false); setPicked(ids) }
  return (
    <div className="space-y-3 rounded-lg border bg-snow p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-lg">{t('duties.who')}</h2>
        <span className="text-sm text-slate-600">{t('duties.whoCount', { chosen: picked.length, total: teachers.length })}</span>
      </div>
      <p className="text-sm text-slate-600">{t('duties.whoHelp')}</p>
      <div className="flex flex-wrap items-center gap-2">
        <input type="search" aria-label={t('admin.actions.search')} placeholder={t('admin.actions.search')} className="rounded border p-1.5 text-sm" value={search} onChange={(e) => setSearch(e.target.value)} />
        <button type="button" className="rounded border px-3 py-1 text-sm text-brand" onClick={() => change(teachers.map((x) => x.id))}>{t('duties.selectAll')}</button>
        <button type="button" className="rounded border px-3 py-1 text-sm text-brand" onClick={() => change([])}>{t('duties.selectNone')}</button>
      </div>
      <ul className="grid max-h-60 gap-2 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">
        {shown.map((x) => (
          <li key={x.id}>
            <label className={`flex cursor-pointer items-center gap-2 rounded-2xl border px-3 py-2 text-sm ${picked.includes(x.id) ? 'border-brand bg-sky-tint/40 font-bold text-deep-harbor' : 'bg-snow'}`}>
              <input type="checkbox" checked={picked.includes(x.id)} onChange={() => change(picked.includes(x.id) ? picked.filter((id) => id !== x.id) : [...picked, x.id])} />{x.name}
            </label>
          </li>
        ))}
      </ul>
      <div className="flex items-center gap-3">
        <InteractiveHoverButton type="button" disabled={save.isPending} className="px-5 py-1.5 disabled:opacity-50" onClick={() => save.mutate()}>{t('admin.actions.save')}</InteractiveHoverButton>
        {saved && <span role="status" className="text-sm text-green-800">{t('duties.whoSaved')}</span>}
      </div>
    </div>
  )
}

// The duty roster: the posts, build the roster of a period (preview, then publish) and fix single duties by hand.
export default function DutiesAdminPage() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const posts = useQuery({ queryKey: ['duties', 'posts'], queryFn: async () => (await api.get('/duties/posts')).data.data })
  const [range, setRange] = useState({ from: '', to: '' })
  const [off, setOff] = useState([])
  const [offDraft, setOffDraft] = useState('')
  const [chosen, setChosen] = useState([])
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState(null)
  const roster = useQuery({ queryKey: ['duties', 'roster', range.from, range.to], queryFn: async () => (await api.get('/duties', { params: range.from && range.to ? range : {} })).data })
  const run = useMutation({
    mutationFn: (dry) => api.post('/duties/generate', { ...range, exclude_dates: off, post_ids: chosen.length ? chosen : null, dry_run: dry }),
    onSuccess: (res) => { setError(''); setResult(res.data.data); if (!res.data.data.dry_run) client.invalidateQueries({ queryKey: ['duties'] }) },
    onError: (err) => { setResult(null); setError(errorOf(err, t('admin.actions.error'))) },
  })
  const list = posts.data ?? []
  const days = roster.data ? [...new Set(roster.data.data.map((d) => d.date))] : []
  return (
    <section className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-2"><h1>{t('duties.title')}</h1><button type="button" className="rounded border px-4 py-1.5 text-brand" onClick={() => setDialog({ type: 'duty' })}>{t('duties.addDuty')}</button></div>
      <p className="text-sm text-slate-600">{t('duties.help')}</p>

      <WhoDoesDuty />

      <div className="space-y-3 rounded-lg border bg-snow p-5">
        <div className="flex items-center justify-between gap-2"><h2 className="text-lg">{t('duties.posts')}</h2><InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={() => setDialog({ type: 'post' })}>{t('duties.newPost')}</InteractiveHoverButton></div>
        {list.length === 0 ? <p className="text-sm text-slate-500">{t('duties.noPosts')}</p> : (
          <ul className="grid gap-2 sm:grid-cols-2">
            {list.map((p) => (
              <li key={p.id} className={`flex items-center justify-between gap-2 rounded-2xl border p-3 ${p.is_active ? '' : 'opacity-50'}`}>
                <span><b className="block text-deep-harbor">{p.name}</b><span className="text-xs text-slate-600" dir="ltr">{p.starts_at} – {p.ends_at}</span><span className="ms-2 text-xs text-slate-600">× {p.staff_count}{p.days ? ` · ${p.days.map((d) => t(`duties.weekdays.${d}`)).join('، ')}` : ''}</span></span>
                <button type="button" className="rounded border px-3 py-1 text-sm text-brand" onClick={() => setDialog({ type: 'post', post: p })}>{t('admin.actions.edit')}</button>
              </li>
            ))}
          </ul>
        )}
      </div>

      <form className="space-y-4 rounded-lg border bg-snow p-5" onSubmit={(e) => { e.preventDefault(); run.mutate(true) }}>
        <h2 className="text-lg">{t('duties.generate')}</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{t('duties.fromDate')}<DateInput required className="mt-1 w-full rounded border p-2" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /></label>
          <label className="block text-sm">{t('duties.toDate')}<DateInput required className="mt-1 w-full rounded border p-2" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></label>
        </div>
        {list.length > 0 && (
          <div className="text-sm">
            <p className="mb-1 font-medium">{t('duties.posts')} <span className="font-normal text-slate-500">({t('duties.gradesHint')})</span></p>
            <div className="flex flex-wrap gap-2">
              {list.filter((p) => p.is_active).map((p) => (
                <label key={p.id} className={`cursor-pointer rounded-full border px-3 py-1 ${chosen.includes(p.id) ? 'bg-brand text-white' : 'bg-snow'}`}>
                  <input type="checkbox" className="sr-only" checked={chosen.includes(p.id)} onChange={() => setChosen(chosen.includes(p.id) ? chosen.filter((x) => x !== p.id) : [...chosen, p.id])} />{p.name}
                </label>
              ))}
            </div>
          </div>
        )}
        <div className="text-sm">
          <p className="mb-1 font-medium">{t('duties.holidays')}</p>
          <div className="flex flex-wrap items-center gap-2">
            {off.map((d) => <span key={d} className="rounded-full bg-sky-tint px-3 py-1" dir="ltr">{d}<button type="button" aria-label={t('admin.actions.delete')} className="ms-2 text-red-700" onClick={() => setOff(off.filter((x) => x !== d))}>✕</button></span>)}
            <DateInput aria-label={t('duties.holidays')} className="rounded border p-1.5" value={offDraft} onChange={(e) => setOffDraft(e.target.value)} />
            <button type="button" disabled={!offDraft} className="rounded border px-3 py-1 text-brand disabled:opacity-40" onClick={() => { setOff([...new Set([...off, offDraft])]); setOffDraft('') }}>{t('duties.addHoliday')}</button>
          </div>
        </div>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {result && (
          <div role="status" className={`rounded-lg p-3 text-sm ${result.dry_run ? 'bg-sky-tint/50' : 'bg-green-50 text-green-900'}`}>
            <p className="font-bold">{t(result.dry_run ? 'duties.previewDone' : 'duties.published', { duties: result.duties, days: result.days })}</p>
            {result.warnings.map((w) => <p key={w} className="mt-1 text-amber-800">⚠ {w}</p>)}
          </div>
        )}
        <div className="flex flex-wrap gap-2">
          <button type="submit" disabled={run.isPending || !range.from || !range.to} className="rounded border px-4 py-2 font-semibold text-brand disabled:opacity-50">{t('duties.preview')}</button>
          <InteractiveHoverButton type="button" disabled={run.isPending || !range.from || !range.to} className="px-5 py-2 disabled:opacity-50" onClick={() => run.mutate(false)}>{t('duties.publish')}</InteractiveHoverButton>
        </div>
      </form>

      <div className="space-y-3">
        <h2 className="text-lg">{t('duties.roster')}{roster.data && <span className="ms-2 text-sm font-normal text-slate-500" dir="ltr">{roster.data.from} → {roster.data.to}</span>}</h2>
        {!roster.data ? <p className="text-slate-500">{t('common.loading')}</p> : days.length === 0 ? <p className="rounded-lg border bg-snow p-6 text-center text-slate-600">{t('duties.none')}</p> : days.map((d) => (
          <section key={d} className="overflow-hidden rounded-lg border bg-snow">
            <h3 className="bg-slate-50 px-4 py-2 text-sm" dir="ltr">{d}</h3>
            <ul className="divide-y">
              {roster.data.data.filter((x) => x.date === d).map((x) => (
                <li key={x.id} className="flex flex-wrap items-center gap-3 px-4 py-2 text-sm">
                  <span className="w-28 text-slate-600" dir="ltr">{x.starts_at} – {x.ends_at}</span>
                  <span className="font-bold text-deep-harbor">{x.location}</span>
                  <span className="min-w-0 flex-1 truncate">{x.user.name}{x.is_locked && <span className="ms-2 rounded-full bg-slate-100 px-2 text-xs">{t('duties.locked')}</span>}</span>
                  <button type="button" className="rounded border px-3 py-1 text-brand" onClick={() => setDialog({ type: 'duty', duty: x })}>{t('admin.actions.edit')}</button>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>
      {dialog?.type === 'post' && <PostDialog post={dialog.post} onClose={() => setDialog(null)} />}
      {dialog?.type === 'duty' && <DutyDialog duty={dialog.duty} posts={list} onClose={() => setDialog(null)} />}
    </section>
  )
}
