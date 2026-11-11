import { DoorOpen } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

const MARK = { error: 'border-s-red-500', warning: 'border-s-amber-400' }

const Pill = ({ selected, onClick, children }) => (
  <button type="button" role="tab" aria-selected={selected} onClick={onClick}
    className={`min-h-10 shrink-0 snap-start whitespace-nowrap rounded-full border px-4 text-sm font-medium transition-colors ${selected ? 'border-brand bg-brand text-white' : 'bg-white text-slate-700'}`}>
    {children}
  </button>
)

// Every timetable on a phone (the school-wide one, a teacher's, a student's, a section's): instead of the wide grid, a row of pills to pick a
// section or a teacher (only when there is more than one), a row of days, and that day's lessons as cards, one per period: time, subject,
// teacher (or section), room. Opens on today when the school has lessons that day.
// columns: [{ id, label }]; cellOf(column, day, number) -> { gone } | { entry, text, sub, room, mark, teacher, unavailable, locked, joint }
// periodAt(day, number) -> { starts_at, ends_at } | undefined; onPick(cell, number, period) makes a card tappable (the grid's cell click);
// highlightTeacher: a teacher id whose lessons stay bright while the others fade.
export default function PhoneTimetable({ columns, columnLabel, days, numbers, cellOf, periodAt, onPick = null, highlightTeacher = '', onlyProblems = false, emptyLabel = null }) {
  const { t, i18n } = useTranslation()
  const [today] = useState(() => new Date().getDay()) // 0 = Sunday, like the periods
  const [dayPick, setDayPick] = useState(null)
  const [columnPick, setColumnPick] = useState('')
  const day = dayPick !== null && days.includes(dayPick) ? dayPick : days.includes(today) ? today : days[0]
  const column = columns.find((c) => String(c.id) === String(columnPick)) ?? columns[0]
  // Five day names must fit one row of a phone: Arabic names are short enough, English ones are cut to three letters (the full name is the label).
  const dayLabel = (d) => (i18n.language === 'ar' ? t(`days.${d}`) : t(`days.${d}`).slice(0, 3))
  const rows = column ? numbers.map((n) => ({ n, cell: cellOf(column, day, n), period: periodAt(day, n) })).filter((r) => !r.cell.gone) : []
  const visible = rows.filter((r) => !onlyProblems || r.cell.mark === 'error' || r.cell.mark === 'warning')

  return (
    <div className="space-y-3 md:hidden print:hidden" data-testid="phone-timetable">
      {columns.length > 1 && (
        <div role="tablist" aria-label={columnLabel} className="-mx-4 flex snap-x scroll-px-4 gap-2 overflow-x-auto px-4 pb-1">
          {columns.map((c) => <Pill key={c.id} selected={column?.id === c.id} onClick={() => setColumnPick(String(c.id))}>{c.label}</Pill>)}
        </div>
      )}
      <div role="tablist" aria-label={t('fullTimetable.days')} className="grid gap-1 rounded-xl bg-slate-100 p-1" style={{ gridTemplateColumns: `repeat(${Math.max(days.length, 1)}, minmax(0, 1fr))` }}>
        {days.map((d) => (
          <button key={d} type="button" role="tab" aria-selected={d === day} aria-label={t(`days.${d}`)} onClick={() => setDayPick(d)}
            className={`min-h-10 truncate rounded-lg px-1 text-[13px] font-medium ${d === day ? 'bg-white text-brand shadow-sm' : 'text-slate-600'}`}>{dayLabel(d)}</button>
        ))}
      </div>

      {visible.length === 0 ? <p className="rounded-xl border bg-white p-6 text-center text-slate-500">{t('fullTimetable.noLessons')}</p> : (
        <ol className="space-y-2">
          {visible.map(({ n, cell, period }) => {
            const mine = highlightTeacher && String(cell.teacher) === String(highlightTeacher)
            const dim = highlightTeacher && !mine && cell.entry
            const edge = cell.unavailable ? 'border-s-red-400' : cell.mark ? MARK[cell.mark] ?? 'border-s-brand' : cell.entry ? 'border-s-brand' : 'border-s-slate-200'
            const body = cell.entry ? (
              <>
                <span className="block text-base font-bold">{cell.text}{cell.joint && <span className="ms-1 text-sm" title={t('timetable.jointMark')}>⇄</span>}{cell.locked && <span role="img" aria-label={t('timetable.locked')} className="ms-1 text-sm">🔒</span>}</span>
                <span className="block text-sm text-slate-600">{cell.sub}</span>
                {cell.room && <span className="mt-1 inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"><DoorOpen size={13} aria-hidden />{cell.room}</span>}
              </>
            ) : cell.unavailable ? <span className="block text-sm font-medium text-red-700">⛔ {t('availability.unavailable')}</span>
              : <span className="block text-sm text-slate-400">{emptyLabel ?? t('fullTimetable.freePeriod')}</span>
            const tappable = onPick && (cell.entry || onPick.always)
            return (
              <li key={n} className={`flex gap-3 rounded-xl border border-s-4 p-3 shadow-sm ${mine ? 'bg-yellow-100' : cell.unavailable ? 'bg-red-50' : 'bg-white'} ${edge} ${dim ? 'opacity-40' : ''}`}>
                <div className="w-16 shrink-0 text-center">
                  <div className="text-xl font-bold leading-none text-brand">{n}</div>
                  <div dir="ltr" className="mt-1 text-[11px] leading-tight text-slate-500">{period && <><span className="block">{period.starts_at}</span><span className="block">{period.ends_at}</span></>}</div>
                </div>
                {tappable
                  ? <button type="button" className="min-w-0 flex-1 text-start" aria-label={cell.entry ? `${cell.text} ${cell.sub}` : `${t(`days.${day}`)} ${t('schoolDay.period', { number: n })}`} onClick={() => onPick(cell, n, period)}>{body}</button>
                  : <div className="min-w-0 flex-1 self-center">{body}</div>}
              </li>
            )
          })}
        </ol>
      )}
    </div>
  )
}
