import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import SignatureCard from '../components/SignatureCard'
import SignatureUpload from '../components/SignatureUpload'

// Electronic signatures for the report card. The admin manages the school's (principal...) and the homeroom teachers';
// a teacher manages only their own (printed when they are the homeroom teacher of the student's section).
export default function SignaturesPage({ admin = false }) {
  const { t } = useTranslation()
  const [teacherId, setTeacherId] = useState('')
  const { data, isLoading } = useQuery({ queryKey: ['signatures'], queryFn: async () => (await api.get('/signatures')).data.data })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  const personal = data.personal ?? []

  return (
    <section className="space-y-6">
      <div>
        <h1 className="text-xl font-bold">{t('signatures.title_page')}</h1>
        <p className="text-sm text-slate-600">{t('signatures.help')}</p>
      </div>

      {admin && (
        <div className="space-y-3">
          <h2 className="text-lg font-bold">{t('signatures.schoolSection')}</h2>
          <p className="text-sm text-slate-600">{t('signatures.schoolHelp')}</p>
          <ul className="grid gap-3 sm:grid-cols-2">{data.school.map((s) => <SignatureCard key={s.id} signature={s} canEditTitle />)}</ul>
          <SignatureUpload scope="school" withTitle label={t('signatures.addSchool')} />
        </div>
      )}

      <div className="space-y-3">
        <h2 className="text-lg font-bold">{admin ? t('signatures.teachersSection') : t('signatures.mine')}</h2>
        <p className="text-sm text-slate-600">{admin ? t('signatures.teachersHelp') : t('signatures.mineHelp')}</p>
        <ul className="grid gap-3 sm:grid-cols-2">
          {personal.map((s) => (
            <div key={s.id} className="space-y-1">
              {admin && s.user && <p className="text-sm font-medium">{s.user.name}</p>}
              <SignatureCard signature={s} />
            </div>
          ))}
        </ul>
        {admin ? (
          <div className="space-y-2">
            <label className="block text-sm">{t('signatures.forTeacher')}
              <select className="mt-1 w-full rounded border p-2" value={teacherId} onChange={(e) => setTeacherId(e.target.value)}>
                <option value="">—</option>
                {data.homeroom_teachers.map((x) => <option key={x.id} value={x.id}>{x.name} ({x.classrooms.join(', ')})</option>)}
              </select>
            </label>
            {teacherId && <SignatureUpload key={teacherId} scope="user" userId={teacherId} label={t('signatures.addForTeacher')} />}
          </div>
        ) : (
          <SignatureUpload scope="user" label={personal.length ? t('signatures.replaceMine') : t('signatures.addMine')} />
        )}
      </div>
    </section>
  )
}
