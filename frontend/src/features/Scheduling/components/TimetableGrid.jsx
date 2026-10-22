import { useTranslation } from 'react-i18next'

// The week as a grid: periods are rows, school days are columns. Today's column is highlighted.
// show: 'teacher' (the teacher's name in each lesson) or 'classroom' (the section's name); emptyLabel: text of a cell without a lesson.
export default function TimetableGrid({ periods, entries, show = 'teacher', emptyLabel = null }) {
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
            <th className="p-2" />
            {days.map((d) => <th key={d} className={`p-2 text-center font-medium ${d === today ? 'bg-brand/10 text-brand' : ''}`}>{t(`days.${d}`)}</th>)}
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
                return (
                  <td key={d} className={`border-s p-1.5 text-center align-top ${d === today ? 'bg-brand/5' : ''}`}>
                    {e && (
                      <div className={`rounded p-1 ${e.is_locked ? 'bg-amber-50' : 'bg-slate-50'}`}>
                        <span className="block font-medium">{subjectName(e.subject)}</span>
                        <span className="block text-xs text-slate-500">{show === 'teacher' ? e.teacher.name : e.classroom.name}</span>
                        {e.is_locked && <span role="img" aria-label={t('timetable.locked')} className="text-xs">🔒</span>}
                      </div>
                    )}
                    {!e && exists && emptyLabel && <span className="text-xs text-slate-400">{emptyLabel}</span>}
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
