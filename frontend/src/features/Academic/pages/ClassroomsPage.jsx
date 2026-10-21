import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import ResourcePage from '../components/ResourcePage'

const fields = [
  { name: 'name', label: 'academic.fields.name' },
  { name: 'grade_level_id', label: 'academic.fields.gradeLevel', type: 'select', optionsFrom: 'grade-levels' },
  { name: 'academic_year_id', label: 'academic.fields.academicYear', type: 'select', optionsFrom: 'academic-years', optionLabel: 'name' },
  { name: 'capacity', label: 'academic.fields.capacity', type: 'number' },
  {
    name: 'gender', label: 'academic.fields.gender', type: 'select',
    options: [{ value: 'male', label: 'buildings.gender.male' }, { value: 'female', label: 'buildings.gender.female' }, { value: 'mixed', label: 'buildings.gender.mixed' }],
  },
  { name: 'room_id', label: 'academic.fields.room', type: 'select', optionsFrom: 'rooms', optionsParams: { kind: 'classroom' }, optionLabel: 'label', getValue: (row) => row.room?.id },
  {
    name: 'homeroom_teacher_id', label: 'people.fields.homeroomTeacher', type: 'select', optionsFrom: 'staff', optionsParams: { homeroom: 1 }, optionLabel: 'name',
    getValue: (row) => row.homeroom_teacher?.id,
  },
]

export default function ClassroomsPage() {
  const { t, i18n } = useTranslation()
  const columns = [
    { key: 'name', label: 'academic.fields.name' },
    { key: 'grade_level', label: 'academic.fields.gradeLevel', render: (r) => r.grade_level?.[`name_${i18n.language}`] },
    { key: 'academic_year', label: 'academic.fields.academicYear', render: (r) => r.academic_year?.name },
    { key: 'capacity', label: 'academic.fields.capacity' },
    { key: 'gender', label: 'academic.fields.gender', render: (r) => (r.gender ? t(`buildings.gender.${r.gender}`) : '—') },
    { key: 'room', label: 'academic.fields.room', render: (r) => r.room?.name ?? '—' },
    { key: 'homeroom_teacher', label: 'people.fields.homeroomTeacher', render: (r) => r.homeroom_teacher?.name },
  ]
  return (
    <ResourcePage
      path="classrooms"
      title="academic.classrooms.title"
      columns={columns}
      fields={fields}
      empty={{ name: '', grade_level_id: '', academic_year_id: '', capacity: '', gender: '', room_id: '', homeroom_teacher_id: '' }}
      actions={<Link to="/admin/classrooms/generate" className="rounded border px-3 py-1.5">{t('generate.title')}</Link>}
    />
  )
}
