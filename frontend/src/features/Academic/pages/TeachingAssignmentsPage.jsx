import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import AutoAssignDialog from '../components/AutoAssignDialog'
import ResourcePage from '../components/ResourcePage'
import ExportButton from '../../../components/shared/ExportButton'
import { useResource } from '../hooks/useResource'

// Who teaches which subject in which classroom. The teacher list only offers the teachers of the chosen subject.
const fields = [
  { name: 'classroom_id', label: 'academic.fields.classroom', type: 'select', optionsFrom: 'classrooms', optionLabel: 'name', getValue: (row) => row.classroom?.id },
  { name: 'subject_id', label: 'academic.fields.subject', type: 'select', optionsFrom: 'subjects', getValue: (row) => row.subject?.id },
  {
    name: 'teacher_id', label: 'academic.fields.teacher', type: 'select', optionsFrom: 'staff', optionLabel: 'name',
    optionsParams: (form) => ({ category: 'teaching', ...(form.subject_id ? { subject_id: form.subject_id } : {}) }),
    getValue: (row) => row.teacher?.id,
  },
  { name: 'weekly_periods', label: 'academic.fields.weeklyPeriods', type: 'number' },
]

export default function TeachingAssignmentsPage() {
  const { t, i18n } = useTranslation()
  const [classroom, setClassroom] = useState('')
  const [auto, setAuto] = useState(false)
  const classrooms = useResource('classrooms').useList({ per_page: 100 })
  const columns = [
    { key: 'classroom', label: 'academic.fields.classroom', render: (r) => r.classroom?.name },
    { key: 'subject', label: 'academic.fields.subject', render: (r) => r.subject?.[`name_${i18n.language}`] },
    { key: 'teacher', label: 'academic.fields.teacher', render: (r) => r.teacher?.name },
    { key: 'weekly_periods', label: 'academic.fields.weeklyPeriods' },
  ]
  return (
    <>
      <label className="mb-3 block text-sm">
        {t('people.filterClassroom')}
        <select className="mt-1 block rounded border p-2" value={classroom} onChange={(e) => setClassroom(e.target.value)}>
          <option value="">{t('people.allClassrooms')}</option>
          {(classrooms.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </label>
      <ResourcePage
        key={classroom}
        filters={classroom ? { classroom_id: classroom } : {}}
        path="teaching-assignments"
        title="academic.teaching.title"
        columns={columns}
        fields={fields}
        empty={{ classroom_id: classroom, subject_id: '', teacher_id: '', weekly_periods: 3 }}
        actions={(
          <>
            <button type="button" className="rounded border px-3 py-1.5" onClick={() => setAuto(true)}>{t('autoAssign.button')}</button>
            <ExportButton path="/teaching-assignments/export" fallbackName="distribution.xlsx" label={t('academic.teaching.exportDistribution')} />
          </>
        )}
      />
      <AutoAssignDialog open={auto} onClose={() => setAuto(false)} />
    </>
  )
}
