import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import ResourcePage from '../../Academic/components/ResourcePage'

const fields = [
  { name: 'student_number', label: 'people.fields.studentNumber' },
  { name: 'name_ar', label: 'academic.fields.nameAr' },
  { name: 'name_en', label: 'academic.fields.nameEn', hint: 'people.nameEnHint' },
  { name: 'birth_date', label: 'people.fields.birthDate', type: 'date' },
  {
    name: 'gender',
    label: 'people.fields.gender',
    type: 'select',
    options: [{ value: 'male', label: 'people.male' }, { value: 'female', label: 'people.female' }],
  },
  { name: 'classroom_id', label: 'people.fields.classroom', type: 'select', optionsFrom: 'classrooms', optionLabel: 'name', getValue: (row) => row.classroom?.id },
  { name: 'guardian_email', label: 'people.fields.guardianEmail', type: 'email', getValue: (row) => row.guardians?.[0]?.email },
  { name: 'guardian_name', label: 'people.fields.guardianName', getValue: (row) => row.guardians?.[0]?.name },
  { name: 'guardian_phone', label: 'people.fields.guardianPhone', getValue: (row) => row.guardians?.[0]?.phone },
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
  const { t } = useTranslation()
  const [account, setAccount] = useState(null)
  return (
    <>
      <ResourcePage
        path="students"
        title="people.students"
        columns={columns}
        fields={fields}
        empty={{
          student_number: '', name_ar: '', name_en: '', birth_date: '', gender: '',
          classroom_id: '', guardian_email: '', guardian_name: '', guardian_phone: '',
        }}
        rowActions={rowActions}
        onSaved={(response, isNew) => isNew && setAccount(response?.data?.account ?? null)}
      />
      <Modal open={account !== null} title={t('people.accountCreated.title')} onClose={() => setAccount(null)}>
        <p className="mb-3 text-sm text-slate-600">{t('people.accountCreated.text')}</p>
        <dl className="space-y-2">
          <div><dt className="text-sm text-slate-500">{t('people.accountCreated.email')}</dt><dd dir="ltr" className="font-mono">{account?.email}</dd></div>
          <div><dt className="text-sm text-slate-500">{t('people.accountCreated.password')}</dt><dd dir="ltr" className="font-mono">{account?.password}</dd></div>
        </dl>
        <div className="flex justify-end pt-4">
          <button type="button" className="rounded bg-brand px-3 py-1.5 text-white" onClick={() => setAccount(null)}>{t('people.accountCreated.close')}</button>
        </div>
      </Modal>
    </>
  )
}
