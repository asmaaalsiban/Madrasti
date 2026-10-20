import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ResourcePage from '../../Academic/components/ResourcePage'

const CATEGORIES = ['all', 'teaching', 'administrative', 'student_affairs', 'health', 'technical', 'services']

const rowActions = [
  {
    key: 'reset',
    label: 'people.actions.resetPassword',
    confirm: 'people.actions.resetConfirm',
    done: 'people.actions.resetDone',
    run: (row) => api.post(`/users/${row.id}/reset-password`),
  },
]

export default function StaffPage() {
  const { t, i18n } = useTranslation()
  const [category, setCategory] = useState('all')
  const { data: types = [] } = useQuery({
    queryKey: ['staff-types'],
    queryFn: async () => (await api.get('/staff-types')).data.data,
    staleTime: Infinity,
  })
  const byValue = Object.fromEntries(types.map((x) => [x.value, x]))
  // New people start on the first type of the open tab; every type stays selectable (to move someone to another category).
  const defaultType = types.find((x) => category === 'all' || x.category === category)?.value ?? 'teacher'

  const fields = [
    { name: 'name', label: 'academic.fields.name' },
    { name: 'email', label: 'people.fields.email', type: 'email', hint: 'people.accountHint' },
    { name: 'phone', label: 'people.fields.phone' },
    { name: 'staff_type', label: 'staff.fields.type', type: 'select', options: types.map((x) => ({ value: x.value, label: `staff.types.${x.value}` })) },
    {
      name: 'subject_id', label: 'staff.fields.subject', type: 'select', optionsFrom: 'subjects',
      getValue: (row) => row.subject?.id, visibleWhen: (form) => byValue[form.staff_type]?.needs_subject,
    },
    { name: 'floor', label: 'staff.fields.floor', visibleWhen: (form) => byValue[form.staff_type]?.needs_floor },
    { name: 'is_active', label: 'people.fields.active', type: 'checkbox' },
  ]

  const columns = [
    { key: 'name', label: 'academic.fields.name' },
    { key: 'email', label: 'people.fields.email' },
    { key: 'staff_type', label: 'staff.fields.type', render: (r) => t(`staff.types.${r.staff_type}`) },
    {
      key: 'subject', label: 'staff.fields.subject',
      render: (r) => r.subject?.[`name_${i18n.language}`] ?? (r.floor ? `${t('staff.fields.floor')} ${r.floor}` : ''),
    },
    { key: 'phone', label: 'people.fields.phone' },
    { key: 'is_active', label: 'people.fields.active', render: (r) => t(r.is_active ? 'academic.yes' : 'academic.no') },
  ]

  return (
    <div className="space-y-4">
      <div role="tablist" className="flex flex-wrap gap-2">
        {CATEGORIES.map((c) => (
          <button
            key={c}
            type="button"
            role="tab"
            aria-selected={category === c}
            className={`rounded-full border px-3 py-1 text-sm ${category === c ? 'bg-brand text-white' : 'bg-white'}`}
            onClick={() => setCategory(c)}
          >
            {t(`staff.categories.${c}`)}
          </button>
        ))}
      </div>
      <ResourcePage
        key={category}
        path="staff"
        title="staff.title"
        columns={columns}
        fields={fields}
        empty={{ name: '', email: '', phone: '', staff_type: defaultType, subject_id: '', floor: '', is_active: true }}
        filters={category === 'all' ? {} : { category }}
        rowActions={rowActions}
      />
    </div>
  )
}
