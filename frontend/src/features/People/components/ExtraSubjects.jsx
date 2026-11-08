import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2 } from 'lucide-react'
import { api } from '../../../api/client'

// More subjects a teacher teaches besides the main one. For each: the subject and the grades it is taught in, picked one by one
// (e.g. Math for four grades and Finance for one). No grade picked = the grades of the teacher.
export default function ExtraSubjects({ value, onChange }) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const subjects = useQuery({ queryKey: ['extra-subjects', 'subjects'], queryFn: async () => (await api.get('/subjects', { params: { per_page: 100 } })).data.data })
  const grades = useQuery({ queryKey: ['extra-subjects', 'grades'], queryFn: async () => (await api.get('/grade-levels', { params: { per_page: 100 } })).data.data })
  const name = (x) => x[`name_${lang}`] || x.name_ar
  const set = (i, patch) => onChange(value.map((r, j) => (j === i ? { ...r, ...patch } : r)))
  const toggle = (i, id) => {
    const now = value[i].grade_ids ?? []
    set(i, { grade_ids: now.includes(id) ? now.filter((x) => x !== id) : [...now, id] })
  }
  return (
    <fieldset className="space-y-3 rounded border border-dashed p-3">
      <legend className="px-1 text-sm">{t('staff.extra.title')} ({value.length})</legend>
      {value.map((r, i) => (
        <div key={i} className="space-y-2 rounded border bg-white p-2">
          <div className="flex items-center gap-2">
            <select aria-label={t('staff.extra.subject')} className="min-w-0 flex-1 rounded border p-2" value={r.subject_id ?? ''} onChange={(e) => set(i, { subject_id: Number(e.target.value) })}>
              <option value="" disabled>{t('staff.extra.subject')}</option>
              {(subjects.data ?? []).map((s) => <option key={s.id} value={s.id}>{name(s)}</option>)}
            </select>
            <button type="button" aria-label={t('admin.actions.delete')} className="text-red-700" onClick={() => onChange(value.filter((_, j) => j !== i))}><Trash2 size={16} aria-hidden /></button>
          </div>
          <div role="group" aria-label={t('staff.extra.grades')} className="flex flex-wrap gap-2 text-sm">
            {(grades.data ?? []).map((g) => (
              <label key={g.id} className={`flex items-center gap-1.5 rounded-full border px-3 py-1 ${(r.grade_ids ?? []).includes(g.id) ? 'border-brand bg-sky-tint' : ''}`}>
                <input type="checkbox" checked={(r.grade_ids ?? []).includes(g.id)} onChange={() => toggle(i, g.id)} />{name(g)}
              </label>
            ))}
          </div>
        </div>
      ))}
      <button type="button" className="inline-flex items-center gap-1 text-sm text-brand underline" onClick={() => onChange([...value, { subject_id: '', grade_ids: [] }])}><Plus size={14} aria-hidden />{t('staff.extra.add')}</button>
      <p className="text-xs text-slate-500">{t('staff.extra.hint')}</p>
    </fieldset>
  )
}
