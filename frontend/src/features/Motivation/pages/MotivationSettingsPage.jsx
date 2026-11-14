import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// A small list manager: add (a form), hide / show, remove.
function Manager({ title, help, path, fields, empty, describe }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [draft, setDraft] = useState(empty)
  const [error, setError] = useState('')
  const list = useQuery({ queryKey: ['motivation', path], queryFn: async () => (await api.get(`/motivation/${path}`)).data.data })
  const refresh = () => client.invalidateQueries({ queryKey: ['motivation'] })
  const fail = (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error'))
  const clean = (row) => Object.fromEntries(Object.entries(row).filter(([, v]) => v !== ''))
  const add = useMutation({ mutationFn: () => api.post(`/motivation/${path}`, clean(draft)), onSuccess: () => { setDraft(empty); setError(''); refresh() }, onError: fail })
  const toggle = useMutation({ mutationFn: (row) => api.put(`/motivation/${path}/${row.id}`, { ...clean(Object.fromEntries(fields.map((f) => [f.key, row[f.key] ?? '']))), is_active: !row.is_active }), onSuccess: refresh })
  const remove = useMutation({ mutationFn: (row) => api.delete(`/motivation/${path}/${row.id}`), onSuccess: refresh })

  return (
    <div className="space-y-3">
      <h2 className="text-lg font-bold">{title}</h2>
      <p className="text-sm text-slate-600">{help}</p>
      <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
        {fields.map((f) => (
          <label key={f.key} className="text-sm">{f.label}
            <input required={f.required !== false} type={f.type ?? 'text'} dir={f.ltr ? 'ltr' : undefined} className="mt-1 block rounded border p-2" style={f.width ? { width: f.width } : undefined}
              value={draft[f.key]} onChange={(e) => setDraft({ ...draft, [f.key]: e.target.value })} />
          </label>
        ))}
        <InteractiveHoverButton type="submit" className="px-4 py-2">{t('admin.actions.add')}</InteractiveHoverButton>
      </form>
      {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
      <ul className="space-y-2">
        {(list.data ?? []).map((row) => (
          <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 rounded border bg-white p-3">
            <span className={row.is_active ? '' : 'text-slate-400 line-through'}>{describe(row)}</span>
            <span className="flex gap-2">
              <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1 text-sm" onClick={() => toggle.mutate(row)}>{row.is_active ? t('behavior.hide') : t('behavior.show')}</InteractiveHoverButton>
              <button type="button" className="rounded border border-red-300 px-3 py-1 text-sm text-red-700" onClick={() => remove.mutate(row)}>{t('admin.actions.delete')}</button>
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}

// The admin's lists: what points are given for, and the badges (earned at a points total or given by hand).
export default function MotivationSettingsPage() {
  const { t } = useTranslation()
  return (
    <section className="space-y-8">
      <h1 className="text-xl font-bold">{t('motivation.settings')}</h1>
      <Manager
        title={t('motivation.categories')} help={t('motivation.categoriesHelp')} path="categories"
        empty={{ name_ar: '', name_en: '', points: '' }}
        fields={[{ key: 'name_ar', label: t('behavior.nameAr') }, { key: 'name_en', label: t('behavior.nameEn'), ltr: true }, { key: 'points', label: t('motivation.points'), type: 'number', ltr: true, width: '6rem' }]}
        describe={(c) => <>{c.name_ar} · <span dir="ltr">{c.name_en}</span> · <b dir="ltr">{c.points > 0 ? `+${c.points}` : c.points}</b></>}
      />
      <Manager
        title={t('motivation.badges')} help={t('motivation.badgesHelp')} path="badges"
        empty={{ name_ar: '', name_en: '', icon: '', points_required: '' }}
        fields={[{ key: 'name_ar', label: t('behavior.nameAr') }, { key: 'name_en', label: t('behavior.nameEn'), ltr: true }, { key: 'icon', label: t('motivation.icon'), width: '5rem' }, { key: 'points_required', label: t('motivation.pointsRequired'), type: 'number', required: false, ltr: true, width: '8rem' }]}
        describe={(b) => <>{b.icon} {b.name_ar} · <span dir="ltr">{b.name_en}</span> · {b.points_required ? t('motivation.atPoints', { count: b.points_required }) : t('motivation.byHand')}</>}
      />
    </section>
  )
}
