import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// What the teacher teaches: one subject, or "Class teacher" (all the subjects of their own section, except the ones kept for specialists).
export default function TeacherSubjectField({ form, setForm }) {
  const { t, i18n } = useTranslation()
  const { data = [] } = useQuery({ queryKey: ['teacher-subject-field'], queryFn: async () => (await api.get('/subjects', { params: { per_page: 100 } })).data.data })
  const value = form.is_class_teacher ? 'class' : String(form.subject_id ?? '')
  const change = (v) => setForm({ ...form, is_class_teacher: v === 'class', subject_id: v === 'class' ? '' : v })
  return (
    <label className="block text-sm">
      {t('staff.fields.subject')}
      <select className="mt-1 w-full rounded border p-2" value={value} onChange={(e) => change(e.target.value)}>
        <option value="">—</option>
        <option value="class">{t('staff.classTeacherOption')}</option>
        {data.map((s) => <option key={s.id} value={s.id}>{s[`name_${i18n.language}`] || s.name_ar}</option>)}
      </select>
      {form.is_class_teacher && <span className="mt-1 block text-xs text-slate-500">{t('staff.classTeacherHint')}</span>}
    </label>
  )
}
