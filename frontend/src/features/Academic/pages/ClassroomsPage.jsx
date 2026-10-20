import { useTranslation } from 'react-i18next'
import ResourcePage from '../components/ResourcePage'

const fields = [
  { name: 'name', label: 'academic.fields.name' },
  { name: 'grade_level_id', label: 'academic.fields.gradeLevel', type: 'select', optionsFrom: 'grade-levels' },
  { name: 'academic_year_id', label: 'academic.fields.academicYear', type: 'select', optionsFrom: 'academic-years', optionLabel: 'name' },
  { name: 'capacity', label: 'academic.fields.capacity', type: 'number' },
  {
    name: 'homeroom_teacher_id', label: 'people.fields.homeroomTeacher', type: 'select', optionsFrom: 'teachers', optionLabel: 'name',
    getValue: (row) => row.homeroom_teacher?.id,
  },
]

export default function ClassroomsPage() {
  const { i18n } = useTranslation()
  const columns = [
    { key: 'name', label: 'academic.fields.name' },
    { key: 'grade_level', label: 'academic.fields.gradeLevel', render: (r) => r.grade_level?.[`name_${i18n.language}`] },
    { key: 'academic_year', label: 'academic.fields.academicYear', render: (r) => r.academic_year?.name },
    { key: 'capacity', label: 'academic.fields.capacity' },
    { key: 'homeroom_teacher', label: 'people.fields.homeroomTeacher', render: (r) => r.homeroom_teacher?.name },
  ]
  return (
    <ResourcePage
      path="classrooms"
      title="academic.classrooms.title"
      columns={columns}
      fields={fields}
      empty={{ name: '', grade_level_id: '', academic_year_id: '', capacity: '', homeroom_teacher_id: '' }}
    />
  )
}
