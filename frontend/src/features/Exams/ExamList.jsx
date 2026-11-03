import { useTranslation } from 'react-i18next'

// Exams grouped by day: a date badge, then each exam with its time, subject, section and who watches.
// `meId`: a teacher's own list: the time shown is the part they watch (their periods), not the whole exam.
export default function ExamList({ sessions, showClass = true, showWatchers = false, empty, meId = null }) {
  const { t, i18n } = useTranslation()
  if (sessions.length === 0) return <p className="rounded-lg border bg-snow p-6 text-center text-slate-600">{empty ?? t('exams.none')}</p>
  const days = [...new Set(sessions.map((s) => s.date))]
  const dayName = (d) => new Date(`${d}T12:00:00`).toLocaleDateString(i18n.language === 'ar' ? 'ar-JO-u-nu-latn' : 'en-GB', { weekday: 'long' })
  const dayDate = (d) => new Date(`${d}T12:00:00`).toLocaleDateString(i18n.language === 'ar' ? 'ar-JO-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'long' })
  const watcherName = (s, u) => (u.period_from !== null && s.period_from !== null && (u.period_from !== s.period_from || u.period_to !== s.period_to) ? `${u.name} (${u.period_from === u.period_to ? t('exams.periodOne', { n: u.period_from }) : t('exams.periodsRange', { from: u.period_from, to: u.period_to })})` : u.name)
  const mine = (s) => (meId ? s.invigilators.find((u) => u.id === meId && u.starts_at) : null)
  const subject = (s) => s.subject[`name_${i18n.language}`] || s.subject.name_ar
  return (
    <div className="space-y-4">
      {days.map((d) => (
        <section key={d} className="flex gap-4 rounded-lg border bg-snow p-4">
          <div className="grid min-h-16 w-20 shrink-0 place-content-center self-start rounded-2xl bg-deep-harbor px-1 py-2 text-center text-white">
            <span className="text-[11px] leading-tight text-white/75">{dayName(d)}</span>
            <span className="text-sm font-extrabold leading-tight">{dayDate(d)}</span>
          </div>
          <ul className="min-w-0 flex-1 divide-y">
            {sessions.filter((s) => s.date === d).map((s) => (
              <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0">
                <span className="font-bold text-deep-harbor">{subject(s)}<span className="ms-2 inline-block whitespace-nowrap rounded-full bg-deep-harbor px-2.5 py-0.5 text-[11px] font-semibold text-white"><bdi>{s.kind === 'final' ? t('exams.kinds.final') : t('exams.monthlyN', { n: s.round })}</bdi></span>{showClass && <span className="ms-2 inline-block whitespace-nowrap rounded-full bg-sky-tint px-3 py-0.5 text-xs"><bdi dir="ltr">{s.classroom.name}</bdi></span>}</span>
                <span className="text-sm text-slate-600">{(mine(s) ?? s).period_from !== null && <span className="me-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs">{(mine(s) ?? s).period_from === (mine(s) ?? s).period_to ? t('exams.periodOne', { n: (mine(s) ?? s).period_from }) : t('exams.periodsRange', { from: (mine(s) ?? s).period_from, to: (mine(s) ?? s).period_to })}</span>}<span dir="ltr">{(mine(s) ?? s).starts_at} – {(mine(s) ?? s).ends_at}</span></span>
                {showWatchers && s.invigilators.length > 0 && <span className="w-full text-xs text-slate-500">{t('exams.watchers')}: {s.invigilators.map((u) => watcherName(s, u)).join('، ')}</span>}
              </li>
            ))}
          </ul>
        </section>
      ))}
      {sessions.some((s) => s.period_from !== null) && <p className="text-center text-xs text-slate-500">{t('exams.lessonsReplaced')}</p>}
    </div>
  )
}
