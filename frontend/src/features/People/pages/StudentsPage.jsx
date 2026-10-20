import { api } from '../../../api/client'
import ResourcePage from '../../Academic/components/ResourcePage'

const fields = [
  { name: 'student_number', label: 'people.fields.studentNumber' },
  { name: 'name_ar', label: 'academic.fields.nameAr' },
  { name: 'name_en', label: 'academic.fields.nameEn' },
  { name: 'birth_date', label: 'people.fields.birthDate', type: 'date' },
  {
    name: 'gender',
    label: 'people.fields.gender',
    type: 'select',
    options: [{ value: 'male', label: 'people.male' }, { value: 'female', label: 'people.female' }],
  },
  { name: 'student_email', label: 'people.fields.studentEmail', type: 'email', getValue: (row) => row.email, hint: 'people.accountHint' },
  { name: 'classroom_id', label: 'people.fields.classroom', type: 'select', optionsFrom: 'classrooms', optionLabel: 'name', getValue: (row) => row.classroom?.id },
  { name: 'guardian_email', label: 'people.fields.guardianEmail', type: 'email', getValue: (row) => row.guardians?.[0]?.email },
  { name: 'guardian_name', label: 'people.fields.guardianName', getValue: (row) => row.guardians?.[0]?.name },
]

const columns = [
  { key: 'student_number', label: 'people.fields.studentNumber' },
  { key: 'name_ar', label: 'academic.fields.nameAr' },
  { key: 'email', label: 'people.fields.studentEmail' },
  { key: 'classroom', label: 'people.fields.classroom', render: (r) => r.classroom?.name },
  { key: 'guardians', label: 'people.fields.guardians', render: (r) => r.guardians?.map((g) => g.email).join(', ') },
]

const rowActions = [
  {
    key: 'reset',
    label: 'people.actions.resetPassword',
    confirm: 'people.actions.resetConfirm',
    done: 'people.actions.resetDone',
    run: (row) => api.post(`/users/${row.user_id}/reset-password`),
  },
  {
    key: 'reset-guardian',
    label: 'people.actions.resetGuardianPassword',
    confirm: 'people.actions.resetConfirm',
    done: 'people.actions.resetDone',
    when: (row) => Boolean(row.guardians?.length),
    run: (row) => api.post(`/users/${row.guardians[0].id}/reset-password`),
  },
]

export default function StudentsPage() {
  return (
    <ResourcePage
      path="students"
      title="people.students"
      columns={columns}
      fields={fields}
      empty={{
        student_number: '', name_ar: '', name_en: '', birth_date: '', gender: '',
        student_email: '', classroom_id: '', guardian_email: '', guardian_name: '',
      }}
      rowActions={rowActions}
    />
  )
}
