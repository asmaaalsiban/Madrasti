import { useTranslation } from 'react-i18next'
import ResourcePage from '../components/ResourcePage'

const fields = [
  { name: 'name', label: 'academic.fields.name' },
  { name: 'starts_on', label: 'academic.fields.startsOn', type: 'date' },
  { name: 'ends_on', label: 'academic.fields.endsOn', type: 'date' },
  { name: 'is_current', label: 'academic.fields.isCurrent', type: 'checkbox' },
]

export default function AcademicYearsPage() {
  const { t } = useTranslation()
  const columns = [
    { key: 'name', label: 'academic.fields.name' },
    { key: 'starts_on', label: 'academic.fields.startsOn' },
    { key: 'ends_on', label: 'academic.fields.endsOn' },
    { key: 'is_current', label: 'academic.fields.isCurrent', render: (r) => t(r.is_current ? 'academic.yes' : 'academic.no') },
  ]
  return <ResourcePage path="academic-years" title="academic.years.title" columns={columns} fields={fields} empty={{ name: '', starts_on: '', ends_on: '', is_current: false }} />
}
