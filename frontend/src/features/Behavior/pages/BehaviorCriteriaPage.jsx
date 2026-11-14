import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// The admin's list of what students are evaluated on: add your own, rename, hide or remove.
export default function BehaviorCriteriaPage() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [draft, setDraft] = useState({ name_ar: '', name_en: '' })
  const [error, setError] = useState('')
  const list = useQuery({ queryKey: ['behavior', 'criteria'], queryFn: async () => (await api.get('/behavior/criteria')).data.data })
  const refresh = () => client.invalidateQueries({ queryKey: ['behavior'] })
  const fail = (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error'))

  const add = useMutation({ mutationFn: () => api.post('/behavior/criteria', draft), onSuccess: () => { setDraft({ name_ar: '', name_en: '' }); setError(''); refresh() }, onError: fail })
  const toggle = useMutation({ mutationFn: (c) => api.put(`/behavior/criteria/${c.id}`, { name_ar: c.name_ar, name_en: c.name_en, is_active: !c.is_active }), onSuccess: refresh })
  const remove = useMutation({ mutationFn: (c) => api.delete(`/behavior/criteria/${c.id}`), onSuccess: refresh })

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('behavior.criteriaTitle')}</h1>
      <p className="text-sm text-slate-600">{t('behavior.criteriaHelp')}</p>
      <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
        <label className="text-sm">{t('behavior.nameAr')}<input required className="mt-1 block rounded border p-2" value={draft.name_ar} onChange={(e) => setDraft({ ...draft, name_ar: e.target.value })} /></label>
        <label className="text-sm">{t('behavior.nameEn')}<input required dir="ltr" className="mt-1 block rounded border p-2" value={draft.name_en} onChange={(e) => setDraft({ ...draft, name_en: e.target.value })} /></label>
        <InteractiveHoverButton type="submit" className="px-4 py-2">{t('admin.actions.add')}</InteractiveHoverButton>
      </form>
      {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
      <ul className="space-y-2">
        {(list.data ?? []).map((c) => (
          <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 rounded border bg-white p-3">
            <span className={c.is_active ? '' : 'text-slate-400 line-through'}>{c.name_ar} · <span dir="ltr">{c.name_en}</span></span>
            <span className="flex gap-2">
              <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1 text-sm" onClick={() => toggle.mutate(c)}>{c.is_active ? t('behavior.hide') : t('behavior.show')}</InteractiveHoverButton>
              <button type="button" className="rounded border border-red-300 px-3 py-1 text-sm text-red-700" onClick={() => remove.mutate(c)}>{t('admin.actions.delete')}</button>
            </span>
          </li>
        ))}
      </ul>
    </section>
  )
}
