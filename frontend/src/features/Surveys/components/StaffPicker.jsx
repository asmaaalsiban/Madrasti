import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// Choose which members of staff a survey goes to: search, filter by position, tick people, or tick everyone shown.
export default function StaffPicker({ picked, onChange }) {
  const { t, i18n } = useTranslation()
  const [search, setSearch] = useState('')
  const [position, setPosition] = useState('')
  const { data, isLoading } = useQuery({ queryKey: ['surveys', 'staff'], queryFn: async () => (await api.get('/surveys/staff')).data.data })
  const staff = data ?? []
  const positions = [...new Set(staff.map((s) => s.staff_type))]
  const q = search.trim().toLowerCase()
  const shown = staff.filter((s) => (!position || s.staff_type === position) && (!q || s.name.toLowerCase().includes(q)))
  const subject = (s) => (s.subject ? s.subject[`name_${i18n.language}`] || s.subject.name_ar : '')
  const toggle = (id) => onChange(picked.includes(id) ? picked.filter((x) => x !== id) : [...picked, id])
  const allShownPicked = shown.length > 0 && shown.every((s) => picked.includes(s.id))
  const toggleShown = () => onChange(allShownPicked ? picked.filter((id) => !shown.some((s) => s.id === id)) : [...new Set([...picked, ...shown.map((s) => s.id)])])

  return (
    <fieldset className="space-y-2 rounded border p-3">
      <legend className="px-1 text-sm font-medium">{t('surveys.pickStaff')}</legend>
      <div className="flex flex-wrap items-center gap-2">
        <input type="search" aria-label={t('surveys.searchStaff')} placeholder={t('surveys.searchStaff')} className="min-w-0 flex-1 rounded border p-2 text-sm" value={search} onChange={(e) => setSearch(e.target.value)} />
        <select aria-label={t('surveys.allPositions')} className="rounded border p-2 text-sm" value={position} onChange={(e) => setPosition(e.target.value)}>
          <option value="">{t('surveys.allPositions')}</option>
          {positions.map((p) => <option key={p} value={p}>{t(`staff.types.${p}`)}</option>)}
        </select>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
        <label className="flex items-center gap-2 font-medium"><input type="checkbox" checked={allShownPicked} onChange={toggleShown} disabled={shown.length === 0} />{t('surveys.selectShown', { count: shown.length })}</label>
        <span className="text-slate-600">{t('surveys.staffChosen', { count: picked.length })}</span>
      </div>
      {isLoading ? <p className="text-sm text-slate-500">{t('common.loading')}</p> : (
        <ul className="max-h-56 space-y-1 overflow-y-auto">
          {shown.map((s) => (
            <li key={s.id}>
              <label className={`flex items-center gap-2 rounded border px-2 py-1 text-sm ${picked.includes(s.id) ? 'border-brand bg-blue-50' : ''}`}>
                <input type="checkbox" checked={picked.includes(s.id)} onChange={() => toggle(s.id)} aria-label={s.name} />
                <span className="font-medium">{s.name}</span>
                <span className="text-xs text-slate-500">{t(`staff.types.${s.staff_type}`)}{subject(s) ? ` · ${subject(s)}` : ''}</span>
              </label>
            </li>
          ))}
          {shown.length === 0 && <li className="p-2 text-sm text-slate-500">{t('surveys.noStaff')}</li>}
        </ul>
      )}
    </fieldset>
  )
}
