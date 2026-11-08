import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2 } from 'lucide-react'
import { api } from '../../../api/client'

// More subjects a teacher teaches besides the main one, each for its own grades (e.g. Math for grades 5-6 and Science for 8-9).
export default function ExtraSubjects({ value, onChange }) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const subjects = useQuery({ queryKey: ['extra-subjects', 'subjects'], queryFn: async () => (await api.get('/subjects', { params: { per_page: 100 } })).data.data })
  const grades = useQuery({ queryKey: ['extra-subjects', 'grades'], queryFn: async () => (await api.get('/grade-levels', { params: { per_page: 100 } })).data.data })
  const name = (x) => x[`name_${lang}`] || x.name_ar
  const set = (i, patch) => onChange(value.map((r, j) => (j === i ? { ...r, ...patch } : r)))
  const gradeSelect = (i, key, label) => (
    <select aria-label={label} className="rounded border p-2" value={value[i][key] ?? ''} onChange={(e) => set(i, { [key]: e.target.value ? Number(e.target.value) : null })}>
      <option value="">{label}</option>
      {(grades.data ?? []).map((g) => <option key={g.id} value={g.id}>{name(g)}</option>)}
    </select>
  )
  return (
    <fieldset className="space-y-2 rounded border border-dashed p-3">
      <legend className="px-1 text-sm">{t('staff.extra.title')} ({value.length})</legend>
      {value.map((r, i) => (
        <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
          <select aria-label={t('staff.extra.subject')} className="rounded border p-2" value={r.subject_id ?? ''} onChange={(e) => set(i, { subject_id: Number(e.target.value) })}>
            <option value="" disabled>{t('staff.extra.subject')}</option>
            {(subjects.data ?? []).map((s) => <option key={s.id} value={s.id}>{name(s)}</option>)}
          </select>
          {gradeSelect(i, 'grade_from_id', t('staff.fields.gradeFrom'))}
          {gradeSelect(i, 'grade_to_id', t('staff.fields.gradeTo'))}
          <button type="button" aria-label={t('admin.actions.delete')} className="text-red-700" onClick={() => onChange(value.filter((_, j) => j !== i))}><Trash2 size={16} aria-hidden /></button>
        </div>
      ))}
      <button type="button" className="inline-flex items-center gap-1 text-sm text-brand underline" onClick={() => onChange([...value, { subject_id: '', grade_from_id: null, grade_to_id: null }])}><Plus size={14} aria-hidden />{t('staff.extra.add')}</button>
      <p className="text-xs text-slate-500">{t('staff.extra.hint')}</p>
    </fieldset>
  )
}
