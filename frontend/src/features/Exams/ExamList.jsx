import { useTranslation } from 'react-i18next'

// Exams grouped by day: a date badge, then each exam with its time, subject, section and who watches.
export default function ExamList({ sessions, showClass = true, showWatchers = false, empty }) {
  const { t, i18n } = useTranslation()
  if (sessions.length === 0) return <p className="rounded-lg border bg-snow p-6 text-center text-slate-600">{empty ?? t('exams.none')}</p>
  const days = [...new Set(sessions.map((s) => s.date))]
  const dayName = (d) => new Date(`${d}T12:00:00`).toLocaleDateString(i18n.language === 'ar' ? 'ar-JO-u-nu-latn' : 'en-GB', { weekday: 'long' })
  const dayDate = (d) => new Date(`${d}T12:00:00`).toLocaleDateString(i18n.language === 'ar' ? 'ar-JO-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'long' })
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
                <span className="font-bold text-deep-harbor">{subject(s)}{showClass && <span className="ms-2 rounded-full bg-sky-tint px-3 py-0.5 text-xs">{s.classroom.name}</span>}</span>
                <span className="text-sm text-slate-600">{s.period_from !== null && <span className="me-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs">{s.period_from === s.period_to ? t('exams.periodOne', { n: s.period_from }) : t('exams.periodsRange', { from: s.period_from, to: s.period_to })}</span>}<span dir="ltr">{s.starts_at} – {s.ends_at}</span></span>
                {showWatchers && s.invigilators.length > 0 && <span className="w-full text-xs text-slate-500">{t('exams.watchers')}: {s.invigilators.map((u) => u.name).join('، ')}</span>}
              </li>
            ))}
          </ul>
        </section>
      ))}
      {sessions.some((s) => s.period_from !== null) && <p className="text-center text-xs text-slate-500">{t('exams.lessonsReplaced')}</p>}
    </div>
  )
}
