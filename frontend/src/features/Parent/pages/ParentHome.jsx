import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import ChildAttendance from '../components/ChildAttendance'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { api } from '../../../api/client'
import AddEmailForm from '../../Auth/components/AddEmailForm'
import { useAuth } from '../../Auth/AuthProvider'

// A parent sees each child with the email the child signs in with.
export default function ParentHome() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const children = user?.children ?? []
  const [addingEmail, setAddingEmail] = useState(false)
  const pending = useQuery({ queryKey: ['my-surveys', 'pending'], queryFn: async () => (await api.get('/my-surveys/pending')).data.data.count, refetchInterval: 60000 })
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('parent.home.title')}</h1>
      {pending.data > 0 && (
        <Link to="/parent/surveys" className="block rounded-lg border border-brand bg-blue-50 p-4 font-medium text-brand">{t('surveys.waiting', { count: pending.data })}</Link>
      )}
      {user && !user.email && (
        <div className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4">
          <p className="font-medium text-amber-900">{t('auth.addEmail.reminder')}</p>
          {addingEmail ? <AddEmailForm onDone={() => setAddingEmail(false)} onSkip={() => setAddingEmail(false)} /> : (
            <button type="button" className="rounded bg-brand px-4 py-2 text-white" onClick={() => setAddingEmail(true)}>{t('auth.addEmail.button')}</button>
          )}
        </div>
      )}
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
              <div className="mt-2 flex flex-wrap gap-2">
                <Link to={`/parent/children/${c.id}/grades`} className="rounded border px-3 py-1 text-sm text-brand">{t('studentGrades.linkLabel')}</Link>
                <Link to={`/parent/children/${c.id}/timetable`} className="rounded border px-3 py-1 text-sm text-brand">{t('timetable.linkLabel')}</Link>
                <Link to={`/parent/children/${c.id}/lesson-plans`} className="rounded border px-3 py-1 text-sm text-brand">{t('lessonPlans.title')}</Link>
                <Link to={`/parent/children/${c.id}/fees`} className="rounded border px-3 py-1 text-sm text-brand">{t('finance.feesLink')}</Link>
                <Link to={`/parent/children/${c.id}/summaries`} className="rounded border px-3 py-1 text-sm text-brand">{t('dailySummary.title')}</Link>
                <Link to={`/parent/children/${c.id}/behavior`} className="rounded border px-3 py-1 text-sm text-brand">{t('behavior.title')}</Link>
                <Link to={`/parent/children/${c.id}/motivation`} className="rounded border px-3 py-1 text-sm text-brand">{t('motivation.title')}</Link>
                <Link to={`/parent/children/${c.id}/homework`} className="rounded border px-3 py-1 text-sm text-brand">{t('homework.title')}</Link>
              </div>
              <ChildAttendance studentId={c.id} />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
