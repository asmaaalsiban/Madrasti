import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import Modal from '../../components/shared/Modal'
import { GROUPS } from './staffFeatures'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

function AccessDialog({ people, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  // One person: their own switches. Several: start from "everything on" and replace what they had.
  const [hidden, setHidden] = useState(people.length === 1 ? people[0].hidden : [])
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => api.put('/staff-visibility', { user_ids: people.map((p) => p.id), hidden }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['staff-visibility'] }); onClose() },
    onError: () => setError(t('admin.actions.error')),
  })
  const toggle = (key) => setHidden(hidden.includes(key) ? hidden.filter((k) => k !== key) : [...hidden, key])
  const title = people.length === 1 ? t('staffAccess.forOne', { name: people[0].name }) : t('staffAccess.forMany', { count: people.length })
  return (
    <Modal open size="lg" title={title} onClose={onClose}>
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {people.length > 1 && <p className="rounded bg-amber-50 p-2 text-sm text-amber-900">{t('staffAccess.replaceWarning')}</p>}
        {GROUPS.map((g) => (
          <fieldset key={g.key} className="space-y-1">
            <legend className="mb-1 text-sm font-bold text-slate-500">{t(`staffAccess.groups.${g.key}`)}</legend>
            <div className="grid gap-1 sm:grid-cols-2">
              {g.features.map((k) => (
                <label key={k} className="flex cursor-pointer items-center gap-2 rounded border bg-white px-3 py-2 text-sm">
                  <input type="checkbox" checked={!hidden.includes(k)} onChange={() => toggle(k)} />
                  {t(`staffAccess.features.${k}`)}
                </label>
              ))}
            </div>
          </fieldset>
        ))}
        <div className="flex flex-wrap justify-between gap-2">
          <span className="flex gap-3 text-sm">
            <button type="button" className="text-brand" onClick={() => setHidden([])}>{t('staffAccess.showAll')}</button>
            <button type="button" className="text-brand" onClick={() => setHidden(GROUPS.flatMap((g) => g.features))}>{t('staffAccess.hideAll')}</button>
          </span>
          <span className="flex gap-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
            <InteractiveHoverButton type="submit" disabled={save.isPending} className="px-5 py-1.5 disabled:opacity-50">{t('admin.actions.save')}</InteractiveHoverButton>
          </span>
        </div>
      </form>
    </Modal>
  )
}

// The principal chooses what each member of staff sees: tick = shown, untick = hidden from their home page, menus and addresses.
export default function StaffAccessPage() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [type, setType] = useState('')
  const [picked, setPicked] = useState([])
  const [dialog, setDialog] = useState(null)
  const { data } = useQuery({ queryKey: ['staff-visibility'], queryFn: async () => (await api.get('/staff-visibility')).data })
  const all = data?.data ?? []
  const types = [...new Set(all.map((p) => p.staff_type).filter(Boolean))]
  const q = search.trim().toLowerCase()
  const shown = all.filter((p) => (!type || p.staff_type === type) && (!q || p.name.toLowerCase().includes(q)))
  const everyone = shown.length > 0 && shown.every((p) => picked.includes(p.id))
  const toggle = (id) => setPicked(picked.includes(id) ? picked.filter((x) => x !== id) : [...picked, id])
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('staffAccess.title')}</h1>
      <p className="text-sm text-slate-600">{t('staffAccess.help')}</p>
      <div className="flex flex-wrap items-center gap-2">
        <input type="search" aria-label={t('admin.actions.search')} placeholder={t('admin.actions.search')} className="min-w-0 flex-1 rounded border p-1.5" value={search} onChange={(e) => setSearch(e.target.value)} />
        <select aria-label={t('tasks.filterByJob')} className="rounded border p-1.5" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">{t('tasks.allJobs')}</option>
          {types.map((k) => <option key={k} value={k}>{t(`staff.types.${k}`)}</option>)}
        </select>
        <InteractiveHoverButton type="button" disabled={picked.length === 0} className="px-4 py-1.5 disabled:opacity-50" onClick={() => setDialog(all.filter((p) => picked.includes(p.id)))}>{t('staffAccess.editSelected', { count: picked.length })}</InteractiveHoverButton>
      </div>
      <div className="overflow-x-auto rounded-lg border bg-white">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-50"><tr>
            <th className="p-2"><input type="checkbox" aria-label={t('tasks.selectAll')} checked={everyone} onChange={() => setPicked(everyone ? picked.filter((id) => !shown.some((p) => p.id === id)) : [...new Set([...picked, ...shown.map((p) => p.id)])])} /></th>
            {['name', 'job', 'hidden'].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`staffAccess.cols.${c}`)}</th>)}<th><span className="sr-only">{t('a11y.actions')}</span></th>
          </tr></thead>
          <tbody>
            {shown.map((p) => (
              <tr key={p.id} className="border-t">
                <td className="p-2"><input type="checkbox" aria-label={p.name} checked={picked.includes(p.id)} onChange={() => toggle(p.id)} /></td>
                <td className="p-2 font-medium"><bdi>{p.name}</bdi></td>
                <td className="p-2 text-slate-600">{p.staff_type ? t(`staff.types.${p.staff_type}`) : '—'}</td>
                <td className="p-2">{p.hidden.length === 0 ? <span className="text-slate-500">{t('staffAccess.seesAll')}</span> : <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{t('staffAccess.hiddenCount', { count: p.hidden.length })}</span>}</td>
                <td className="p-2 text-end"><button type="button" className="rounded border px-3 py-1 text-brand" onClick={() => setDialog([p])}>{t('admin.actions.edit')}</button></td>
              </tr>
            ))}
            {data && shown.length === 0 && <tr><td colSpan={5} className="p-4 text-center text-slate-500">{t('tasks.noMatch')}</td></tr>}
          </tbody>
        </table>
      </div>
      {dialog && <AccessDialog key={dialog.map((p) => p.id).join('-')} people={dialog} onClose={() => { setDialog(null); setPicked([]) }} />}
    </section>
  )
}
