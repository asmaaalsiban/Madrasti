import { useTranslation } from 'react-i18next'

// The week as a grid: periods are rows, school days are columns. Today's column is highlighted.
// onCellClick(period, entry): makes every cell a button (the admin edits a lesson); marked: Set of period ids shown as taken (a teacher's unavailable periods).
// show: 'teacher' (the teacher's name in each lesson) or 'classroom' (the section's name); emptyLabel: text of a cell without a lesson.
export default function TimetableGrid({ periods, entries, show = 'teacher', emptyLabel = null, onCellClick = null, marked = null }) {
  const { t, i18n } = useTranslation()
  const days = [...new Set(periods.map((p) => p.day_of_week))].sort((a, b) => a - b)
  const numbers = [...new Set(periods.map((p) => p.number))].sort((a, b) => a - b)
  const periodAt = (day, number) => periods.find((p) => p.day_of_week === day && p.number === number)
  const entryAt = (day, number) => {
    const p = periodAt(day, number)
    return p && entries.find((e) => e.period_id === p.id)
  }
  const timeOf = (number) => {
    const p = periods.find((x) => x.number === number)
    return p ? `${p.starts_at} - ${p.ends_at}` : ''
  }
  const subjectName = (s) => s[`name_${i18n.language}`] || s.name_ar
  const today = new Date().getDay() // 0 = Sunday, like the periods

  return (
    <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
      <table className="w-full border-collapse text-start text-sm">
        <thead className="bg-slate-100">
          <tr>
            <th><span className="sr-only">{t('a11y.corner')}</span></th>
            {days.map((d) => <th key={d} className={`p-2 text-center font-medium ${d === today ? 'bg-brand/10 text-deep-harbor' : ''}`}>{t(`days.${d}`)}</th>)}
          </tr>
        </thead>
        <tbody>
          {numbers.map((n) => (
            <tr key={n} className="border-t">
              <td className="whitespace-nowrap p-2 text-xs">
                <span className="block font-medium">{t('schoolDay.period', { number: n })}</span>
                <span dir="ltr" className="block text-slate-500">{timeOf(n)}</span>
              </td>
              {days.map((d) => {
                const e = entryAt(d, n)
                const exists = Boolean(periodAt(d, n))
                const period = periodAt(d, n)
                const cell = (
                  <>
                    {e && (
                      <div className={`rounded p-1 ${e.is_locked ? 'bg-amber-50' : 'bg-slate-50'}`}>
                        <span className="block font-medium">{subjectName(e.subject)}</span>
                        <span className="block text-xs text-slate-500">{show === 'teacher' ? e.teacher.name : e.classroom.name}</span>
                        {e.is_locked && <span role="img" aria-label={t('timetable.locked')} className="text-xs">🔒</span>}
                      </div>
                    )}
                    {marked?.has(period?.id) && <span className="text-xs text-red-700">⛔ {t('availability.unavailable')}</span>}
                    {!e && exists && emptyLabel && <span className="text-xs text-slate-400">{emptyLabel}</span>}
                  </>
                )
                return (
                  <td key={d} className={`border-s p-1.5 text-center align-top ${d === today ? 'bg-brand/5' : ''} ${marked?.has(period?.id) ? 'bg-red-50' : ''}`}>
                    {onCellClick && exists ? (
                      <button type="button" className="block min-h-10 w-full rounded hover:bg-slate-100" aria-label={`${t(`days.${d}`)} ${t('schoolDay.period', { number: n })}`} onClick={() => onCellClick(period, e ?? null)}>{cell}</button>
                    ) : cell}
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
