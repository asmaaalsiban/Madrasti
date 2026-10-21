import ResourcePage from '../components/ResourcePage'

const fields = [
  { name: 'academic_year_id', label: 'academic.fields.academicYear', type: 'select', optionsFrom: 'academic-years', optionLabel: 'name', getValue: (row) => row.academic_year?.id },
  { name: 'name', label: 'academic.fields.name' },
  { name: 'starts_on', label: 'academic.fields.startsOn', type: 'date' },
  { name: 'ends_on', label: 'academic.fields.endsOn', type: 'date' },
]

const columns = [
  { key: 'name', label: 'academic.fields.name' },
  { key: 'academic_year', label: 'academic.fields.academicYear', render: (r) => r.academic_year?.name },
  { key: 'starts_on', label: 'academic.fields.startsOn' },
  { key: 'ends_on', label: 'academic.fields.endsOn' },
]

export default function TermsPage() {
  return <ResourcePage path="terms" title="academic.terms.title" columns={columns} fields={fields} empty={{ academic_year_id: '', name: '', starts_on: '', ends_on: '' }} />
}
