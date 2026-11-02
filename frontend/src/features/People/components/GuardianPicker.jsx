import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// Search the parents who already have an account; picking one fills the parent fields of the student form,
// so the new child is linked to the same account.
export default function GuardianPicker({ setForm }) {
  const { t, i18n } = useTranslation()
  const [term, setTerm] = useState('')
  const search = term.trim()
  const { data, isFetching } = useQuery({
    queryKey: ['guardians', search],
    queryFn: async () => (await api.get('/guardians', { params: { search, per_page: 8 } })).data.data,
    enabled: search.length >= 2,
  })
  const childName = (c) => c[`name_${i18n.language}`] || c.name_ar

  const pick = (g) => {
    setForm((form) => ({ ...form, guardian_email: g.email ?? '', guardian_name: g.name, guardian_phone: g.phone ?? '' }))
    setTerm('')
  }

  return (
    <div className="space-y-2 rounded border border-dashed p-3">
      <label className="block text-sm">
        {t('people.guardianPicker.label')}
        <input className="mt-1 w-full rounded border p-2" value={term} placeholder={t('people.guardianPicker.placeholder')} onChange={(e) => setTerm(e.target.value)} />
      </label>
      {search.length >= 2 && data?.length === 0 && !isFetching && <p className="text-sm text-slate-500">{t('people.guardianPicker.none')}</p>}
      {search.length >= 2 && data?.length > 0 && (
        <ul className="space-y-1">
          {data.map((g) => (
            <li key={g.id}>
              <button type="button" className="w-full rounded border p-2 text-start text-sm hover:bg-slate-50" onClick={() => pick(g)}>
                <span className="font-bold">{g.name}</span>
                <span dir="ltr" className="block text-slate-600">{[g.email, g.phone].filter(Boolean).join(' · ')}</span>
                {g.children.length > 0 && <span className="block text-slate-500">{g.children.map(childName).join('، ')}</span>}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
