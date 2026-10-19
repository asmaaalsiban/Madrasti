import ResourcePage from '../components/ResourcePage'

const columns = [
  { key: 'code', label: 'academic.fields.code' },
  { key: 'name_ar', label: 'academic.fields.nameAr' },
  { key: 'name_en', label: 'academic.fields.nameEn' },
]
const fields = columns.map((c) => ({ name: c.key, label: c.label }))

export default function SubjectsPage() {
  return <ResourcePage path="subjects" title="academic.subjects.title" columns={columns} fields={fields} empty={{ code: '', name_ar: '', name_en: '' }} />
}
