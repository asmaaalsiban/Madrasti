import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// Choose the sections (classrooms) a teacher teaches. Nothing chosen = any section that fits their grades and students.
export default function SectionPicker({ value, onChange }) {
  const { t } = useTranslation()
  const [term, setTerm] = useState('')
  const { data = [] } = useQuery({ queryKey: ['section-picker'], queryFn: async () => (await api.get('/classrooms', { params: { per_page: 100 } })).data.data })
  const shown = data.filter((c) => c.name.toLowerCase().includes(term.trim().toLowerCase()))
  const toggle = (id) => onChange(value.includes(id) ? value.filter((x) => x !== id) : [...value, id].sort((a, b) => a - b))

  return (
    <fieldset className="space-y-2 rounded border border-dashed p-3">
      <legend className="px-1 text-sm">{t('staff.sections.title')} ({value.length})</legend>
      <input className="w-full rounded border p-2 text-sm" placeholder={t('staff.sections.search')} aria-label={t('staff.sections.search')} value={term} onChange={(e) => setTerm(e.target.value)} />
      <div className="grid max-h-40 grid-cols-3 gap-1 overflow-y-auto text-sm sm:grid-cols-4">
        {shown.map((c) => (
          <label key={c.id} className="flex items-center gap-1.5">
            <input type="checkbox" checked={value.includes(c.id)} onChange={() => toggle(c.id)} />
            {c.name}
          </label>
        ))}
      </div>
      <p className="text-xs text-slate-500">{t('staff.sections.hint')}</p>
    </fieldset>
  )
}
