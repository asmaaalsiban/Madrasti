import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ResourcePage from '../../Academic/components/ResourcePage'

const fields = [
  { name: 'name', label: 'academic.fields.name' },
  { name: 'email', label: 'people.fields.email', type: 'email', hint: 'people.accountHint' },
  { name: 'phone', label: 'people.fields.phone' },
  { name: 'is_active', label: 'people.fields.active', type: 'checkbox' },
]

const rowActions = [
  {
    key: 'reset',
    label: 'people.actions.resetPassword',
    confirm: 'people.actions.resetConfirm',
    done: 'people.actions.resetDone',
    run: (row) => api.post(`/users/${row.id}/reset-password`),
  },
]

export default function TeachersPage() {
  const { t } = useTranslation()
  const columns = [
    { key: 'name', label: 'academic.fields.name' },
    { key: 'email', label: 'people.fields.email' },
    { key: 'phone', label: 'people.fields.phone' },
    { key: 'is_active', label: 'people.fields.active', render: (r) => t(r.is_active ? 'academic.yes' : 'academic.no') },
  ]
  return (
    <ResourcePage
      path="teachers"
      title="people.teachers"
      columns={columns}
      fields={fields}
      empty={{ name: '', email: '', phone: '', is_active: true }}
      rowActions={rowActions}
    />
  )
}
