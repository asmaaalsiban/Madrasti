import { DoorOpen } from 'lucide-react'
import { useTranslation } from 'react-i18next'

const MARK = { error: 'border-s-red-500', warning: 'border-s-amber-400' }

const Pill = ({ selected, onClick, children }) => (
  <button type="button" role="tab" aria-selected={selected} onClick={onClick}
    className={`min-h-10 shrink-0 snap-start whitespace-nowrap rounded-full border px-4 text-sm font-medium transition-colors ${selected ? 'border-brand bg-brand text-white' : 'bg-white text-slate-700'}`}>
    {children}
  </button>
)

// The school-wide timetable for a phone: instead of the wide grid, pick a section (or a teacher) in a row of pills, pick a day, and read that
// day's lessons as cards, one per period: time, subject, teacher (or section), room. Tapping a card highlights that teacher everywhere, like the grid.
// columns: [{ id, label }]; cellOf(column, day, number) -> { gone } | { entry, mark, teacher, text, sub, room }; periodAt(day, number) -> { starts_at, ends_at } | undefined.
export default function PhoneTimetable({ columns, columnLabel, days, numbers, cellOf, periodAt, teacherId, onlyProblems, onPickTeacher, columnId, onColumn, day, onDay }) {
  const { t, i18n } = useTranslation()
  // Five day names must fit one row of a phone: Arabic names are short enough, English ones are cut to three letters (the full name is the label).
  const dayLabel = (d) => (i18n.language === 'ar' ? t(`days.${d}`) : t(`days.${d}`).slice(0, 3))
  const column = columns.find((c) => String(c.id) === String(columnId)) ?? columns[0]
  const rows = column ? numbers.map((n) => ({ n, cell: cellOf(column, day, n), period: periodAt(day, n) })).filter((r) => !r.cell.gone) : []
  const visible = rows.filter((r) => !onlyProblems || r.cell.mark === 'error' || r.cell.mark === 'warning')

  return (
    <div className="space-y-3 md:hidden print:hidden" data-testid="phone-timetable">
      <div role="tablist" aria-label={columnLabel} className="-mx-4 flex snap-x scroll-px-4 gap-2 overflow-x-auto px-4 pb-1">
        {columns.map((c) => <Pill key={c.id} selected={column?.id === c.id} onClick={() => onColumn(String(c.id))}>{c.label}</Pill>)}
      </div>
      <div role="tablist" aria-label={t('fullTimetable.days')} className="grid gap-1 rounded-xl bg-slate-100 p-1" style={{ gridTemplateColumns: `repeat(${Math.max(days.length, 1)}, minmax(0, 1fr))` }}>
        {days.map((d) => (
          <button key={d} type="button" role="tab" aria-selected={d === day} aria-label={t(`days.${d}`)} onClick={() => onDay(d)}
            className={`min-h-10 truncate rounded-lg px-1 text-[13px] font-medium ${d === day ? 'bg-white text-brand shadow-sm' : 'text-slate-600'}`}>{dayLabel(d)}</button>
        ))}
      </div>

      {visible.length === 0 ? <p className="rounded-xl border bg-white p-6 text-center text-slate-500">{t('fullTimetable.noLessons')}</p> : (
        <ol className="space-y-2">
          {visible.map(({ n, cell, period }) => {
            const mine = teacherId && String(cell.teacher) === teacherId
            const dim = teacherId && !mine && cell.entry
                        return (
              <li key={n} className={`flex gap-3 rounded-xl border border-s-4 bg-white p-3 shadow-sm ${cell.mark ? MARK[cell.mark] ?? 'border-s-brand' : cell.entry ? 'border-s-brand' : 'border-s-slate-200'} ${mine ? 'bg-yellow-100' : ''} ${dim ? 'opacity-40' : ''}`}>
                <div className="w-16 shrink-0 text-center">
                  <div className="text-xl font-bold leading-none text-brand">{n}</div>
                  <div dir="ltr" className="mt-1 text-[11px] leading-tight text-slate-500">{period && <><span className="block">{period.starts_at}</span><span className="block">{period.ends_at}</span></>}</div>
                </div>
                {cell.entry ? (
                  <button type="button" className="min-w-0 flex-1 text-start" aria-label={`${cell.text} ${cell.sub}`} onClick={() => onPickTeacher(String(cell.teacher))}>
                    <span className="block text-base font-bold">{cell.text}</span>
                    <span className="block text-sm text-slate-600">{cell.sub}</span>
                    {cell.room && <span className="mt-1 inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"><DoorOpen size={13} aria-hidden />{cell.room}</span>}
                  </button>
                ) : <p className="flex-1 self-center text-sm text-slate-400">{t('fullTimetable.freePeriod')}</p>}
              </li>
            )
          })}
        </ol>
      )}
    </div>
  )
}
