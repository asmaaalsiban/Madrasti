import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import ChildAttendance from '../components/ChildAttendance'
import { useAuth } from '../../Auth/AuthProvider'

// A parent sees each child with the email the child signs in with.
export default function ParentHome() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const children = user?.children ?? []
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('parent.home.title')}</h1>
      {children.length === 0 ? (
        <p className="text-slate-600">{t('parent.home.empty')}</p>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2">
          {children.map((c) => (
            <li key={c.id} className="space-y-1 rounded-lg border bg-white p-4">
              <p className="font-bold">{c[`name_${i18n.language}`] ?? c.name_ar}</p>
              <p className="text-sm text-slate-600">{c.student_number}</p>
              {c.classroom && <p className="text-sm text-slate-600">{c.classroom}</p>}
              <p className="pt-1 text-xs text-slate-500">{t('parent.home.emailHint')}</p>
              <p dir="ltr" className="font-mono text-sm">{c.email}</p>
              <Link to={`/parent/children/${c.id}/grades`} className="mt-2 inline-block rounded border px-3 py-1 text-sm text-brand">{t('studentGrades.linkLabel')}</Link>
              <ChildAttendance studentId={c.id} />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
