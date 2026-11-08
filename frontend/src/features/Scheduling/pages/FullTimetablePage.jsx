import { useQuery } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Printer } from 'lucide-react'
import { api } from '../../../api/client'
import ExportButton from '../../../components/shared/ExportButton'

const SEVERITY = {
  error: { dot: 'bg-red-600', box: 'border-red-400 bg-red-50', cell: 'outline outline-2 -outline-offset-2 outline-red-500' },
  warning: { dot: 'bg-amber-500', box: 'border-amber-300 bg-amber-50', cell: 'outline outline-2 -outline-offset-2 outline-amber-400' },
  info: { dot: 'bg-sky-500', box: 'border-sky-200 bg-sky-50', cell: '' },
}

// The whole school's week in one table (admin): a column per section (or per teacher), a row per period, one day at a time or the whole week.
// Problems found by the checker are marked in the cells; pick a teacher to see every lesson they teach.
export default function FullTimetablePage() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const [axis, setAxis] = useState('sections')
  const [day, setDay] = useState('all')
  const [gradeId, setGradeId] = useState('')
  const [teacherId, setTeacherId] = useState('')
  const [onlyProblems, setOnlyProblems] = useState(false)
  const overview = useQuery({ queryKey: ['timetable', 'overview'], queryFn: async () => (await api.get('/timetable/overview')).data.data })
  const check = useQuery({ queryKey: ['timetable', 'check'], queryFn: async () => (await api.get('/timetable/check')).data.data })

  const view = useMemo(() => {
    const d = overview.data
    if (!d) return null
    const days = [...new Set(d.periods.map((p) => p.day_of_week))].sort((a, b) => a - b)
    const periodId = new Map(d.periods.map((p) => [`${p.day_of_week}-${p.number}`, p.id]))
    const byCell = new Map(d.entries.map((e) => [`${e.classroom_id}-${e.period_id}`, e]))
    const byTeacher = new Map()
    d.entries.forEach((e) => byTeacher.set(`${e.teacher.id}-${e.period_id}`, [...(byTeacher.get(`${e.teacher.id}-${e.period_id}`) ?? []), e]))
    const teachers = [...new Map(d.entries.map((e) => [e.teacher.id, e.teacher])).values()].sort((a, b) => a.name.localeCompare(b.name))
    const numbers = [...new Set(d.periods.map((p) => p.number))].sort((a, b) => a - b)
    return { d, days, periodId, byCell, byTeacher, teachers, numbers }
  }, [overview.data])

  // Problems by cell: `${classroom}-${period}` and `${teacher}-${period}`.
  const marks = useMemo(() => {
    const m = new Map()
    const rank = { error: 3, warning: 2, info: 1 }
    ;(check.data?.issues ?? []).forEach((i) => {
      if (!i.period_id) return
      for (const key of [`c${i.classroom_id}-${i.period_id}`, `t${i.teacher_id}-${i.period_id}`]) {
        if (!m.has(key) || rank[i.severity] > rank[m.get(key)]) m.set(key, i.severity)
      }
    })
    return m
  }, [check.data])

  if (!view) return <p className="text-slate-500">{t('common.loading')}</p>
  const { d, days, periodId, byCell, byTeacher, teachers, numbers } = view
  const name = (x) => x[`name_${lang}`] || x.name_ar
  const rooms = d.classrooms.filter((c) => !gradeId || String(c.grade_level_id) === gradeId)
  const grades = [...new Map(d.classrooms.map((c) => [c.grade_level_id, c])).values()]
  const shownDays = day === 'all' ? days : [Number(day)]
  const counts = check.data?.counts

  const columns = axis === 'sections' ? rooms.map((c) => ({ id: c.id, label: c.name, sub: c.homeroom })) : teachers.map((x) => ({ id: x.id, label: x.name }))
  const cellOf = (col, dayNo, number) => {
    const pid = periodId.get(`${dayNo}-${number}`)
    if (pid === undefined) return { gone: true }
    if (axis === 'sections') {
      const room = d.classrooms.find((c) => c.id === col.id)
      if (room.periods_per_day && number > room.periods_per_day) return { gone: true }
      const e = byCell.get(`${col.id}-${pid}`)
      return { entry: e, mark: marks.get(`c${col.id}-${pid}`), teacher: e?.teacher.id, text: e && `${name(e.subject)}${e.is_joint ? ' ⇄' : ''}`, sub: e?.teacher.name }
    }
    const list = byTeacher.get(`${col.id}-${pid}`) ?? []
    const e = list[0]
    return { entry: e, mark: marks.get(`t${col.id}-${pid}`), teacher: col.id, text: e && `${name(e.subject)}${e.is_joint ? ' ⇄' : ''}`, sub: list.map((x) => d.classrooms.find((c) => c.id === x.classroom_id)?.name).join(' + ') }
  }

  const tab = (key, label) => <button key={key} type="button" role="tab" aria-selected={String(day) === String(key)} className={`rounded-full border px-3 py-1 text-sm ${String(day) === String(key) ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => setDay(key)}>{label}</button>
  const issueText = (i) => t(`fullTimetable.types.${i.type}`, { ...i.params, subject: lang === 'ar' ? i.params.subject_ar ?? i.params.subject : i.params.subject, day: i.params.day !== undefined ? t(`days.${i.params.day}`) : '' })

  return (
    <section className="space-y-4">
      <style>{'@media print { @page { size: A3 landscape; margin: 8mm } }'}</style>
      <div className="flex flex-wrap items-center justify-between gap-2 print:hidden">
        <h1 className="text-xl font-bold">{t('fullTimetable.title')}</h1>
        <div className="flex flex-wrap gap-2">
          <Link to="/admin/timetable" className="rounded border px-3 py-1.5 text-sm text-brand">{t('fullTimetable.editLessons')}</Link>
          <button type="button" className="inline-flex items-center gap-2 rounded border px-3 py-1.5 text-sm text-brand" onClick={() => window.print()}><Printer size={15} aria-hidden />{t('acctReports.print')}</button>
          <ExportButton path="/timetable/export" fallbackName="timetable.xlsx" />
        </div>
      </div>

      {counts && (
        <p role="status" className={`rounded-lg border p-3 text-sm print:hidden ${counts.error > 0 ? SEVERITY.error.box : counts.warning > 0 ? SEVERITY.warning.box : 'border-green-300 bg-green-50'}`}>
          {counts.error + counts.warning + counts.info === 0 ? t('fullTimetable.allGood') : t('fullTimetable.summary', counts)}
        </p>
      )}

      <div className="flex flex-wrap items-end gap-3 print:hidden">
        <div role="tablist" aria-label={t('fullTimetable.days')} className="flex flex-wrap gap-2">{tab('all', t('fullTimetable.wholeWeek'))}{days.map((x) => tab(x, t(`days.${x}`)))}</div>
        <div role="tablist" aria-label={t('fullTimetable.axis')} className="flex gap-2">
          <button type="button" role="tab" aria-selected={axis === 'sections'} className={`rounded-full border px-3 py-1 text-sm ${axis === 'sections' ? 'bg-deep-harbor text-white' : 'bg-white'}`} onClick={() => setAxis('sections')}>{t('fullTimetable.bySections')}</button>
          <button type="button" role="tab" aria-selected={axis === 'teachers'} className={`rounded-full border px-3 py-1 text-sm ${axis === 'teachers' ? 'bg-deep-harbor text-white' : 'bg-white'}`} onClick={() => setAxis('teachers')}>{t('fullTimetable.byTeachers')}</button>
        </div>
        {axis === 'sections' && (
          <label className="text-sm">{t('fullTimetable.grade')}
            <select className="mt-1 block rounded border p-1.5" value={gradeId} onChange={(e) => setGradeId(e.target.value)}>
              <option value="">{t('finance.all')}</option>
              {grades.map((g) => <option key={g.grade_level_id} value={g.grade_level_id}>{g[`grade_${lang}`] || g.grade_ar}</option>)}
            </select>
          </label>
        )}
        <label className="text-sm">{t('fullTimetable.highlight')}
          <select className="mt-1 block rounded border p-1.5" value={teacherId} onChange={(e) => setTeacherId(e.target.value)}>
            <option value="">—</option>
            {teachers.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
          </select>
        </label>
      </div>

      {shownDays.map((dayNo) => (
        <div key={dayNo} className="overflow-x-auto rounded-lg border bg-white">
          <table className="w-full border-collapse text-center text-xs">
            <caption className="bg-deep-harbor p-2 text-start text-sm font-bold text-white">{t(`days.${dayNo}`)}</caption>
            <thead className="bg-slate-100">
              <tr>
                <th scope="col" className="sticky start-0 z-10 w-12 bg-slate-100 p-1.5">#</th>
                {columns.map((c) => <th key={c.id} scope="col" className="min-w-24 border-s p-1.5 font-medium">{c.label}{c.sub && <span className="block text-[10px] font-normal text-slate-500">{c.sub}</span>}</th>)}
              </tr>
            </thead>
            <tbody>
              {numbers.map((n) => (
                <tr key={n} className="border-t">
                  <th scope="row" className="sticky start-0 z-10 bg-slate-50 p-1.5">{n}</th>
                  {columns.map((c) => {
                    const cell = cellOf(c, dayNo, n)
                    if (cell.gone) return <td key={c.id} className="border-s bg-slate-100 text-slate-300">·</td>
                    const mine = teacherId && String(cell.teacher) === teacherId
                    const dim = teacherId && !mine && cell.entry
                    const shown = !onlyProblems || cell.mark === 'error' || cell.mark === 'warning'
                    return (
                      <td key={c.id} className={`border-s p-1 align-top ${cell.mark ? SEVERITY[cell.mark].cell : ''} ${mine ? 'bg-yellow-100' : ''} ${dim ? 'opacity-30' : ''}`}>
                        {cell.entry && shown && (
                          <button type="button" className="w-full text-start" onClick={() => setTeacherId(String(cell.teacher) === teacherId ? '' : String(cell.teacher))} aria-label={`${cell.text} ${cell.sub}`}>
                            <span className="block font-medium leading-tight">{cell.text}</span>
                            <span className="block text-[10px] text-slate-500">{cell.sub}</span>
                          </button>
                        )}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}

      <label className="flex items-center gap-2 text-sm print:hidden"><input type="checkbox" checked={onlyProblems} onChange={(e) => setOnlyProblems(e.target.checked)} />{t('fullTimetable.onlyProblems')}</label>

      <div className="space-y-2 print:hidden">
        <h2 className="font-bold">{t('fullTimetable.problems')}</h2>
        {!check.data ? <p className="text-slate-500">{t('common.loading')}</p> : check.data.issues.length === 0 ? <p className="text-slate-500">{t('fullTimetable.none')}</p> : (
          <ul className="space-y-1.5 text-sm">
            {check.data.issues.map((i, idx) => (
              <li key={idx} className={`flex items-start gap-2 rounded border p-2 ${SEVERITY[i.severity].box}`}>
                <span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${SEVERITY[i.severity].dot}`} aria-hidden />
                <span><span className="sr-only">{t(`fullTimetable.severity.${i.severity}`)}: </span>{issueText(i)}</span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}
