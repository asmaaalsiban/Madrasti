import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { LiveAttachments } from '../../components/shared/Attachments'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

// The student's file (birth certificate, records, ID copies...): find the student by name or number, then add their files.
export default function StudentFilesPage() {
  const { t, i18n } = useTranslation()
  const [search, setSearch] = useState('')
  const [picked, setPicked] = useState(null)
  const nameOf = (s) => s[`name_${i18n.language}`] || s.name_ar
  const found = useQuery({
    queryKey: ['student-files', 'search', search],
    enabled: search.trim().length >= 1 && !picked,
    queryFn: async () => (await api.get('/students', { params: { search: search.trim(), per_page: 8 } })).data.data,
  })
  // The picked student again after every upload or removal, so the list of files is always current.
  const current = useQuery({
    queryKey: ['student-files', 'one', picked?.id],
    enabled: Boolean(picked),
    queryFn: async () => ((await api.get('/students', { params: { search: picked.student_number, per_page: 20 } })).data.data).find((s) => s.id === picked.id) ?? picked,
  })
  const student = current.data ?? picked
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('studentFiles.title')}</h1>
      <p className="text-sm text-slate-600">{t('studentFiles.help')}</p>

      {!picked ? (
        <div className="space-y-2 rounded-lg border bg-white p-4">
          <label className="block text-sm">{t('studentFiles.find')}
            <input className="mt-1 w-full rounded border p-2" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('studentFiles.findHint')} autoFocus />
          </label>
          {search.trim() !== '' && (found.data ?? []).length === 0 && !found.isLoading && <p className="text-sm text-slate-500">{t('studentFiles.none')}</p>}
          <ul className="divide-y">
            {(found.data ?? []).map((s) => (
              <li key={s.id}>
                <button type="button" className="flex w-full items-center justify-between gap-2 py-2 text-start hover:bg-sky-wash/40" onClick={() => setPicked(s)}>
                  <span><bdi className="font-medium">{nameOf(s)}</bdi>{s.classroom && <span className="text-sm text-slate-500"> · {s.classroom.name}</span>}</span>
                  <span className="text-sm text-slate-600" dir="ltr">{s.student_number}</span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      ) : (
        <div className="space-y-4 rounded-lg border bg-white p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p><bdi className="text-lg font-bold">{nameOf(student)}</bdi> <span className="text-sm text-slate-600" dir="ltr">#{student.student_number}</span></p>
            <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5 text-sm" onClick={() => { setPicked(null); setSearch('') }}>{t('studentFiles.change')}</InteractiveHoverButton>
          </div>
          <LiveAttachments ownerType="student" ownerId={student.id} items={student.files ?? []} label={t('studentFiles.files')} invalidate={['student-files']} />
          {(student.files ?? []).length === 0 && <p className="text-sm text-slate-500">{t('studentFiles.empty')}</p>}
        </div>
      )}
    </section>
  )
}
