import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Bus, Award, BookOpenCheck, CalendarClock, FileBadge, FileCheck2, FileText, NotebookPen, Smile, Star, Vote } from 'lucide-react'
import { api } from '../../../api/client'
import SectionTiles from '../../../components/shared/SectionTiles'
import { useAuth } from '../../Auth/AuthProvider'
import StudentGrades from '../../Grading/components/StudentGrades'

// The student's home: a welcome band, every section as an icon tile, then their published grades.
export default function StudentHome() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const pending = useQuery({ queryKey: ['my-surveys', 'pending'], queryFn: async () => (await api.get('/my-surveys/pending')).data.data.count, refetchInterval: 60000, enabled: Boolean(user?.student_id) })
  const tiles = [
    { to: '/student/timetable', label: t('timetable.myTimetable'), icon: CalendarClock },
    { to: '/student/homework', label: t('homework.title'), icon: BookOpenCheck },
    { to: '/student/exams', label: t('exams.myTitle'), icon: FileCheck2 },
    { to: '/student/summaries', label: t('dailySummary.title'), icon: FileText },
    { to: '/student/lesson-plans', label: t('lessonPlans.title'), icon: NotebookPen },
    { to: '/student/report-card', label: t('reportCard.title'), icon: FileBadge },
    { to: '/student/behavior', label: t('behavior.title'), icon: Smile },
    { to: '/student/motivation', label: t('motivation.title'), icon: Star },
    { to: '/student/transport', label: t('transport.myTitle'), icon: Bus },
    { to: '/student/surveys', label: t('surveys.mine'), icon: Vote, badge: pending.data },
  ]
  return (
    <div className="space-y-6">
      <section className="relative overflow-hidden rounded-[28px] bg-deep-harbor p-6 text-white sm:p-8">
        <Award aria-hidden size={140} className="absolute -end-6 -top-6 text-white/10" />
        <p className="text-sm text-white/75">{t('student.welcome')}</p>
        <h1 className="mt-1 !text-white">{user?.name}</h1>
        {user?.classroom && <span className="mt-3 inline-block rounded-full bg-sky-tint px-4 py-1 text-sm font-bold text-deep-harbor">{user.classroom}</span>}
      </section>

      {pending.data > 0 && (
        <Link to="/student/surveys" className="flex items-center justify-between rounded-lg border border-brand bg-sky-tint/40 p-4 font-bold text-deep-harbor">
          <span>{t('surveys.waiting', { count: pending.data })}</span><span aria-hidden>←</span>
        </Link>
      )}

      <SectionTiles tiles={tiles} />

      <section className="space-y-3">
        <h2 className="text-lg">{t('studentGrades.myGrades')}</h2>
        {user?.student_id ? <StudentGrades studentId={user.student_id} /> : <p className="rounded-lg border bg-snow p-6 text-center text-slate-600">{t('studentGrades.noRecord')}</p>}
      </section>
    </div>
  )
}
