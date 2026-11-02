import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// Teacher / admin: pick a section, tick students, give or take points, hand a badge, crown the star of the week.
export default function MotivationPage({ base }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [classroomId, setClassroomId] = useState('')
  const [picked, setPicked] = useState([])
  const [custom, setCustom] = useState({ points: '', note: '' })
  const [badgeId, setBadgeId] = useState('')
  const [newBadge, setNewBadge] = useState({ name_ar: '', name_en: '', icon: '' })
  const [error, setError] = useState('')
  const name = (x) => x[`name_${i18n.language}`] || x.name_ar

  const classrooms = useQuery({ queryKey: ['behavior', 'classrooms'], queryFn: async () => (await api.get('/behavior/classrooms')).data.data })
  const active = classroomId || classrooms.data?.[0]?.id
  const roster = useQuery({
    queryKey: ['motivation', 'classroom', active],
    queryFn: async () => (await api.get('/motivation/classroom', { params: { classroom_id: active } })).data.data,
    enabled: Boolean(active),
  })
  const students = roster.data?.students ?? []

  const refresh = () => client.invalidateQueries({ queryKey: ['motivation'] })
  const fail = (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error'))
  const award = useMutation({
    mutationFn: (body) => api.post('/motivation/awards', { student_ids: picked, ...body }),
    onSuccess: () => { setError(''); setCustom({ points: '', note: '' }); setPicked([]); refresh() },
    onError: fail,
  })
  const giveBadge = useMutation({
    mutationFn: () => Promise.all(picked.map((id) => api.post(`/motivation/students/${id}/badges`, { badge_id: Number(badgeId) }))),
    onSuccess: () => { setError(''); setBadgeId(''); setPicked([]); refresh() },
    onError: fail,
  })
  const createBadge = useMutation({
    mutationFn: async () => (await api.post('/motivation/badges', newBadge)).data.data,
    onSuccess: (badge) => { setError(''); setNewBadge({ name_ar: '', name_en: '', icon: '' }); setBadgeId(String(badge.id)); refresh() },
    onError: fail,
  })
  const crown = useMutation({
    mutationFn: (studentId) => api.put('/motivation/star', { classroom_id: active, student_id: studentId }),
    onSuccess: refresh,
    onError: fail,
  })
  const toggle = (id) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))
  const sign = (n) => (n > 0 ? `+${n}` : String(n))

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('motivation.title')}</h1>
        {base === '/admin' && <Link to="/admin/motivation-settings" className="rounded border px-3 py-1.5 text-sm text-brand">{t('motivation.settings')}</Link>}
      </div>
      <label className="block text-sm">{t('academic.fields.classroom')}
        <select className="mt-1 block rounded border p-2" value={active ?? ''} onChange={(e) => { setClassroomId(e.target.value); setPicked([]) }}>
          {(classrooms.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </label>
      {classrooms.isSuccess && classrooms.data.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('behavior.noClassrooms')}</p>}

      {students.length > 0 && (
        <div className="space-y-3 rounded border bg-white p-3">
          <div className="flex flex-wrap items-center gap-3">
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={picked.length === students.length} onChange={(e) => setPicked(e.target.checked ? students.map((s) => s.id) : [])} />
              {t('motivation.selectAll')}
            </label>
            <span className="text-sm text-slate-600">{t('motivation.selected', { count: picked.length })}</span>
          </div>
          <div className="flex flex-wrap gap-2">
            {(roster.data?.categories ?? []).map((c) => (
              <button key={c.id} type="button" disabled={picked.length === 0 || award.isPending} onClick={() => award.mutate({ point_category_id: c.id })}
                className={`rounded border px-3 py-1.5 text-sm disabled:opacity-40 ${c.points > 0 ? 'border-green-300 text-green-800' : 'border-red-300 text-red-700'}`}>
                {name(c)} <b dir="ltr">{sign(c.points)}</b>
              </button>
            ))}
          </div>
          <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); award.mutate({ points: Number(custom.points), note: custom.note }) }}>
            <label className="text-sm">{t('motivation.points')}<input type="number" required min="-20" max="20" dir="ltr" className="mt-1 block w-24 rounded border p-2" value={custom.points} onChange={(e) => setCustom({ ...custom, points: e.target.value })} /></label>
            <label className="text-sm">{t('motivation.note')}<input required className="mt-1 block rounded border p-2" value={custom.note} onChange={(e) => setCustom({ ...custom, note: e.target.value })} /></label>
            <button type="submit" disabled={picked.length === 0 || award.isPending} className="rounded bg-brand px-4 py-2 text-white disabled:opacity-40">{t('motivation.give')}</button>
          </form>
          <div className="flex flex-wrap items-end gap-2">
            <label className="text-sm">{t('motivation.badge')}
              <select className="mt-1 block rounded border p-2" value={badgeId} onChange={(e) => setBadgeId(e.target.value)}>
                <option value="">—</option>
                <option value="new">＋ {t('motivation.addOwnBadge')}</option>
                {(roster.data?.badges ?? []).map((b) => <option key={b.id} value={b.id}>{b.icon} {name(b)}</option>)}
              </select>
            </label>
            {badgeId !== 'new' && <button type="button" disabled={picked.length === 0 || !badgeId || giveBadge.isPending} onClick={() => giveBadge.mutate()} className="rounded border px-4 py-2 text-brand disabled:opacity-40">{t('motivation.giveBadge')}</button>}
          </div>
          {badgeId === 'new' && (
            <div className="flex flex-wrap items-end gap-2 rounded border bg-slate-50 p-2">
              <label className="text-sm">{t('behavior.nameAr')}<input className="mt-1 block rounded border p-2" value={newBadge.name_ar} onChange={(e) => setNewBadge({ ...newBadge, name_ar: e.target.value })} /></label>
              <label className="text-sm">{t('behavior.nameEn')}<input dir="ltr" className="mt-1 block rounded border p-2" value={newBadge.name_en} onChange={(e) => setNewBadge({ ...newBadge, name_en: e.target.value })} /></label>
              <label className="text-sm">{t('motivation.icon')}<input className="mt-1 block w-20 rounded border p-2" value={newBadge.icon} onChange={(e) => setNewBadge({ ...newBadge, icon: e.target.value })} /></label>
              <button type="button" disabled={!newBadge.name_ar || !newBadge.name_en || !newBadge.icon || createBadge.isPending} onClick={() => createBadge.mutate()} className="rounded bg-brand px-4 py-2 text-white disabled:opacity-40">{t('motivation.saveBadge')}</button>
            </div>
          )}
          {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        </div>
      )}

      <ul className="space-y-2">
        {students.map((s) => (
          <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 rounded border bg-white p-3">
            <label className="flex items-center gap-2">
              <input type="checkbox" aria-label={name(s)} checked={picked.includes(s.id)} onChange={() => toggle(s.id)} />
              <span>{name(s)}</span>
              {s.is_star && <span title={t('motivation.star')} className="rounded-full bg-yellow-100 px-2 py-0.5 text-xs text-yellow-800">⭐ {t('motivation.star')}</span>}
              {!s.is_star && s.is_leader && <span className="rounded-full bg-orange-100 px-2 py-0.5 text-xs text-orange-800">🔥 {t('motivation.leading')}</span>}
            </label>
            <span className="flex flex-wrap items-center gap-2 text-sm">
              <span className="text-slate-600">{t('motivation.weekPoints')}: <b dir="ltr">{sign(s.week)}</b></span>
              <span>{t('motivation.totalPoints')}: <b dir="ltr">{s.total}</b></span>
              <button type="button" disabled={s.is_star || crown.isPending} onClick={() => crown.mutate(s.id)} className="rounded border px-3 py-1 text-brand disabled:opacity-40">{t('motivation.crown')}</button>
              <Link to={`${base}/motivation/students/${s.id}`} className="rounded border px-3 py-1 text-brand">{t('motivation.details')}</Link>
            </span>
          </li>
        ))}
      </ul>
    </section>
  )
}
