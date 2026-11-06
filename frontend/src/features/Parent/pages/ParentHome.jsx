import { Link } from 'react-router-dom'
import useBadges from '../../Badges/useBadges'
import { useTranslation } from 'react-i18next'
import { useState } from 'react'
import { Bus, BookOpenCheck, CalendarClock, ClipboardList, ListChecks, FileBadge, FileCheck2, FileText, NotebookPen, Smile, Star, Wallet } from 'lucide-react'
import SectionTiles from '../../../components/shared/SectionTiles'
import AddEmailForm from '../../Auth/components/AddEmailForm'
import { useAuth } from '../../Auth/AuthProvider'
import ChildAttendance from '../components/ChildAttendance'

const initialOf = (name) => (name ?? '?').trim().charAt(0)

// The parent's home: a welcome band with one tab per child, then the chosen child at a glance (attendance) and every section as an icon tile.
export default function ParentHome() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const children = user?.children ?? []
  const [picked, setPicked] = useState(null)
  const [addingEmail, setAddingEmail] = useState(false)
  const pending = { data: useBadges().surveys }
  const nameOf = (c) => c[`name_${i18n.language}`] ?? c.name_ar
  const child = children.find((c) => c.id === picked) ?? children[0]
  const base = child ? `/parent/children/${child.id}` : ''
  const tiles = child ? [
    { key: 'report-card', to: `${base}/report-card`, label: t('reportCard.title'), icon: FileBadge },
    { key: 'grades', to: `${base}/grades`, label: t('studentGrades.linkLabel'), icon: ClipboardList },
    { key: 'timetable', to: `${base}/timetable`, label: t('timetable.linkLabel'), icon: CalendarClock },
    { key: 'homework', to: `${base}/homework`, label: t('homework.title'), icon: BookOpenCheck },
    { key: 'quizzes', to: `${base}/quizzes`, label: t('quiz.title'), icon: ListChecks },
    { key: 'exams', to: `${base}/exams`, label: t('exams.myTitle'), icon: FileCheck2 },
    { key: 'summaries', to: `${base}/summaries`, label: t('dailySummary.title'), icon: FileText },
    { key: 'lesson-plans', to: `${base}/lesson-plans`, label: t('lessonPlans.title'), icon: NotebookPen },
    { key: 'behavior', to: `${base}/behavior`, label: t('behavior.title'), icon: Smile },
    { key: 'motivation', to: `${base}/motivation`, label: t('motivation.title'), icon: Star },
    { key: 'transport', to: `${base}/transport`, label: t('transport.myTitle'), icon: Bus },
    { key: 'fees', to: `${base}/fees`, label: t('finance.feesLink'), icon: Wallet },
  ] : []

  return (
    <div className="space-y-6">
      <section className="rounded-[28px] bg-deep-harbor p-6 text-white sm:p-8">
        <p className="text-sm text-white/75">{t('parent.home.welcome', { name: user?.name })}</p>
        <h1 className="mt-1 !text-white">{t('parent.home.title')}</h1>
        {children.length > 1 && (
          <div role="tablist" aria-label={t('parent.home.title')} className="mt-5 flex flex-wrap gap-2">
            {children.map((c) => (
              <button key={c.id} type="button" role="tab" aria-selected={c.id === child.id} onClick={() => setPicked(c.id)}
                className={`flex items-center gap-2 rounded-full py-1.5 pe-4 ps-1.5 text-sm font-bold ${c.id === child.id ? '!bg-white !text-deep-harbor' : 'bg-white/15 text-white'}`}>
                <span aria-hidden className={`grid h-8 w-8 place-items-center rounded-full ${c.id === child.id ? 'bg-sky-tint' : 'bg-white/20'}`}>{initialOf(nameOf(c))}</span>
                {nameOf(c)}
              </button>
            ))}
          </div>
        )}
      </section>

      {pending.data > 0 && (
        <Link to="/parent/surveys" className="flex items-center justify-between rounded-lg border border-brand bg-sky-tint/40 p-4 font-bold text-deep-harbor">
          <span>{t('surveys.waiting', { count: pending.data })}</span><span aria-hidden>←</span>
        </Link>
      )}
      {user && !user.email && (
        <div className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4">
          <p className="font-medium text-amber-900">{t('auth.addEmail.reminder')}</p>
          {addingEmail ? <AddEmailForm onDone={() => setAddingEmail(false)} onSkip={() => setAddingEmail(false)} /> : (
            <button type="button" className="rounded bg-brand px-4 py-2 text-white" onClick={() => setAddingEmail(true)}>{t('auth.addEmail.button')}</button>
          )}
        </div>
      )}

      {!child ? (
        <p className="rounded-lg border bg-snow p-6 text-center text-slate-600">{t('parent.home.empty')}</p>
      ) : (
        <>
          <section className="flex flex-wrap items-center gap-4 rounded-lg border bg-snow p-5">
            <span aria-hidden className="grid h-16 w-16 shrink-0 place-items-center rounded-full bg-sky-tint text-2xl font-extrabold text-deep-harbor">{initialOf(nameOf(child))}</span>
            <div className="min-w-0 flex-1">
              <h2 className="text-xl">{nameOf(child)}</h2>
              <p className="text-sm text-slate-600">
                {child.classroom && <span className="me-2 rounded-full bg-sky-tint px-3 py-0.5 text-xs font-bold text-deep-harbor">{child.classroom}</span>}
                <span dir="ltr">{child.student_number}</span>
              </p>
            </div>
            <div className="w-full sm:w-auto sm:min-w-64"><ChildAttendance studentId={child.id} /></div>
          </section>

          <section className="space-y-3">
            <h2 className="text-lg">{t('parent.home.follow', { name: nameOf(child) })}</h2>
            <SectionTiles tiles={tiles} surface="parent_home" />
          </section>

          <p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
            {t('parent.home.emailHint')}: <span dir="ltr" className="font-mono font-bold text-deep-harbor">{child.email}</span>
          </p>
        </>
      )}
    </div>
  )
}
