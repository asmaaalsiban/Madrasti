import ResourcePage from '../components/ResourcePage'

const columns = [
  { key: 'name_ar', label: 'academic.fields.nameAr' },
  { key: 'name_en', label: 'academic.fields.nameEn' },
  { key: 'sort_order', label: 'academic.fields.sortOrder' },
  { key: 'track', label: 'academic.fields.track' },
]
const fields = [
  { name: 'name_ar', label: 'academic.fields.nameAr' },
  { name: 'name_en', label: 'academic.fields.nameEn' },
  { name: 'sort_order', label: 'academic.fields.sortOrder', type: 'number' },
  { name: 'track', label: 'academic.fields.track', hint: 'academic.fields.trackHint' },
]

export default function GradeLevelsPage() {
  return <ResourcePage path="grade-levels" title="academic.grades.title" columns={columns} fields={fields} empty={{ name_ar: '', name_en: '', sort_order: 0, track: '' }} />
}
