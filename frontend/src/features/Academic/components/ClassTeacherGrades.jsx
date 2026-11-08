import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// One grade: is it taught by a class teacher, and which of its subjects are kept for specialist teachers (English, computer...)?
function GradeRow({ grade, subjects, cells, onSaved }) {
  const { t, i18n } = useTranslation()
  const name = (x) => x[`name_${i18n.language}`] || x.name_ar
  const mine = cells.filter((c) => c.grade_level_id === grade.id)
  const [on, setOn] = useState(Boolean(grade.class_teacher_grade))
  const [specialists, setSpecialists] = useState(mine.filter((c) => c.specialist).map((c) => c.subject_id))
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => api.put('/curriculum/class-teachers', { grade_level_id: grade.id, class_teacher_grade: on, specialist_subject_ids: specialists }),
    onSuccess: () => { setError(''); onSaved() },
    onError: (err) => setError(err.response?.data?.message || t('admin.actions.error')),
  })
  const toggle = (id) => setSpecialists(specialists.includes(id) ? specialists.filter((x) => x !== id) : [...specialists, id])
  return (
    <li className="space-y-2 rounded-lg border bg-white p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <label className="flex items-center gap-2 font-bold"><input type="checkbox" checked={on} onChange={(e) => setOn(e.target.checked)} aria-label={`${t('classTeachers.enable')} ${name(grade)}`} />{name(grade)}</label>
        <button type="button" disabled={save.isPending} className="rounded bg-brand px-3 py-1 text-sm text-white disabled:opacity-50" aria-label={`${t('admin.actions.save')} ${name(grade)}`} onClick={() => save.mutate()}>{t('admin.actions.save')}</button>
      </div>
      {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
      {on && (
        <fieldset className="text-sm">
          <legend className="mb-1 text-slate-600">{t('classTeachers.pickSpecialist')}</legend>
          {mine.length === 0 ? <p className="text-slate-500">{t('classTeachers.noSubjects')}</p> : (
            <div className="flex flex-wrap gap-2">
              {mine.map((c) => {
                const subject = subjects.find((s) => s.id === c.subject_id)
                const isSpecialist = specialists.includes(c.subject_id)
                return (
                  <label key={c.subject_id} className={`flex items-center gap-2 rounded-full border px-3 py-1 ${isSpecialist ? 'border-amber-400 bg-amber-50' : 'bg-sky-tint'}`}>
                    <input type="checkbox" checked={isSpecialist} onChange={() => toggle(c.subject_id)} aria-label={`${name(subject)} ${t('classTeachers.specialist')}`} />
                    {name(subject)}<span className="text-xs text-slate-500">{isSpecialist ? t('classTeachers.specialist') : t('classTeachers.byClassTeacher')}</span>
                  </label>
                )
              })}
            </div>
          )}
        </fieldset>
      )}
    </li>
  )
}

export default function ClassTeacherGrades({ grades, subjects, cells, onSaved }) {
  const { t } = useTranslation()
  return (
    <section className="space-y-3">
      <h2 className="text-lg font-bold">{t('classTeachers.title')}</h2>
      <p className="text-sm text-slate-600">{t('classTeachers.intro')}</p>
      <ul className="space-y-2">{grades.map((g) => <GradeRow key={`${g.id}-${g.class_teacher_grade}`} grade={g} subjects={subjects} cells={cells} onSaved={onSaved} />)}</ul>
    </section>
  )
}
