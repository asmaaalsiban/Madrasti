import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import SchoolLogo from '../../../components/shared/SchoolLogo'
import { api } from '../../../api/client'
import { useSchool } from '../../../tenant/useSchool'

const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`)
const RESULT = { pass: 'pass', fail: 'fail', pending: 'pending', none: 'none' }
// The bar under a subject on a phone: green for a strong mark, amber for a pass, red below the pass mark, grey when there is no mark yet.
const barColor = (v, pass) => (v === null || v === undefined ? 'bg-slate-300' : v < pass ? 'bg-red-500' : v >= 85 ? 'bg-green-500' : 'bg-amber-400')

// The student's report card: grades by subject for every term with the annual result, averages, attendance and
// behaviour, laid out as one A4 page. "Print" opens the browser's print dialog (which can also save a PDF).
export default function ReportCard({ studentId }) {
  const { t, i18n } = useTranslation()
  const { data: school } = useSchool()
  const { data, isLoading, isError } = useQuery({ queryKey: ['report-card', String(studentId)], queryFn: async () => (await api.get(`/students/${studentId}/report-card`)).data.data })
  if (isLoading) return <p className="text-slate-500">{t('common.loading')}</p>
  if (isError || !data) return <p role="alert" className="text-red-700">{t('admin.actions.error')}</p>

  const name = (x) => x?.[`name_${i18n.language}`] || x?.name_ar || ''
  const level = (score) => (score === null ? '—' : t(`behavior.levels.${Math.min(5, Math.max(1, Math.round(score)))}`))
  const noGrades = data.subjects.length === 0
  // The signatures at the bottom: the homeroom teacher, then the school's (principal...), then a line for the parent.
  const signers = [
    ...(data.signatures?.homeroom ? [{ key: 'homeroom', title: t('reportCard.sign.homeroom'), name: data.signatures.homeroom.name, image: data.signatures.homeroom.image }] : [{ key: 'homeroom', title: t('reportCard.sign.homeroom') }]),
    ...(data.signatures?.school ?? []).map((s) => ({ key: `s${s.id}`, title: s.title, name: s.name, image: s.image })),
    ...((data.signatures?.school ?? []).length === 0 ? [{ key: 'principal', title: t('reportCard.sign.principal') }] : []),
    { key: 'parent', title: t('reportCard.sign.parent') },
  ]

  return (
    <div className="space-y-3">
      <div className="flex justify-end print:hidden">
        {/* On a phone the button floats above the tab bar so it is always in reach; on a bigger screen it sits at the top. */}
        <button type="button" className="fixed end-4 bottom-[calc(5rem+env(safe-area-inset-bottom))] z-30 rounded-full bg-brand px-6 py-3 font-semibold text-white shadow-lg sm:static sm:rounded sm:px-4 sm:py-2 sm:font-normal sm:shadow-none" onClick={() => window.print()}>{t('reportCard.print')}</button>
      </div>

      <article className="print-area mx-auto max-w-3xl space-y-4 rounded-lg border bg-white p-4 text-slate-900 sm:space-y-5 sm:p-6 shadow-sm print:max-w-none print:rounded-none print:border-0 print:p-8 print:shadow-none" aria-label={t('reportCard.title')}>
        <header className="flex items-center gap-4 border-b-2 border-brand pb-3">
          <SchoolLogo url={school?.logo_url} className="h-16 w-16 !rounded-lg !bg-transparent !p-0 !shadow-none" />
          <div className="flex-1">
            <h2 className="text-xl font-bold">{school?.name}</h2>
            <p className="text-lg">{t('reportCard.title')}{data.year ? ` · ${data.year.name}` : ''}</p>
          </div>
        </header>

        <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3 sm:gap-x-6 sm:gap-y-1">
          <div className="rounded-lg border bg-slate-50 p-2 sm:border-0 sm:bg-transparent sm:p-0"><dt className="text-slate-500">{t('reportCard.student')}</dt><dd className="font-bold">{name(data.student)}</dd></div>
          <div className="rounded-lg border bg-slate-50 p-2 sm:border-0 sm:bg-transparent sm:p-0"><dt className="text-slate-500">{t('reportCard.number')}</dt><dd dir="ltr" className="text-start font-medium">{data.student.student_number}</dd></div>
          <div className="col-span-2 rounded-lg border bg-slate-50 p-2 sm:col-span-1 sm:border-0 sm:bg-transparent sm:p-0"><dt className="text-slate-500">{t('reportCard.classroom')}</dt><dd className="font-medium">{[data.student.grade && name(data.student.grade), data.student.classroom].filter(Boolean).join(' · ') || '—'}</dd></div>
        </dl>

        {noGrades ? <p className="rounded bg-slate-50 p-4 text-slate-600">{t('reportCard.empty')}</p> : (
          <>
          {/* A phone: one card per subject (its marks, the annual mark with a bar, the result), then the average, attendance and behaviour. */}
          <div className="space-y-3 sm:hidden print:hidden" data-testid="report-cards">
            {data.subjects.map((s) => (
              <section key={s.subject.id} className="rounded-xl border bg-white p-3 shadow-sm">
                <h3 className="font-bold">{name(s.subject)}</h3>
                <dl className="mt-2 grid gap-1 text-center" style={{ gridTemplateColumns: `repeat(${data.terms.length + 2}, minmax(0, 1fr))` }}>
                  {data.terms.map((term) => <div key={term.id}><dt className="text-[11px] text-slate-500">{term.name}</dt><dd dir="ltr" className="font-bold">{pct(s.terms[term.id]?.percent)}</dd></div>)}
                  <div><dt className="text-[11px] text-slate-500">{t('reportCard.annual')}</dt><dd dir="ltr" className="font-bold">{pct(s.annual)}</dd></div>
                  <div><dt className="text-[11px] text-slate-500">{t('reportCard.result')}</dt><dd className={`font-bold ${s.annual_result === 'fail' ? 'text-red-700' : ''}`}>{t(`reportCard.results.${RESULT[s.annual_result]}`)}</dd></div>
                </dl>
                <div role="progressbar" aria-label={name(s.subject)} aria-valuemin={0} aria-valuemax={100} aria-valuenow={s.annual ?? 0} className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                  <div className={`h-full rounded-full ${barColor(s.annual, data.pass_percent)}`} style={{ width: `${Math.min(100, Math.max(0, s.annual ?? 0))}%` }} />
                </div>
              </section>
            ))}
            <section className="rounded-xl border bg-brand/10 p-3">
              <div className="flex items-center justify-between font-bold">
                <span>{t('reportCard.average')}</span>
                <span dir="ltr">{pct(data.annual_average)}</span>
                <span className={data.annual_average !== null && data.annual_average < data.pass_percent ? 'text-red-700' : ''}>{data.annual_average === null ? '—' : t(`reportCard.results.${data.annual_average >= data.pass_percent ? 'pass' : 'fail'}`)}</span>
              </div>
            </section>
            <section className="rounded-xl border bg-slate-50 p-3 text-sm">
              <div className="grid gap-1 text-center" style={{ gridTemplateColumns: `minmax(5rem, 1.4fr) repeat(${data.terms.length + 1}, minmax(0, 1fr))` }}>
                <span />
                {data.terms.map((term) => <span key={term.id} className="text-[11px] text-slate-500">{term.name}</span>)}
                <span className="text-[11px] text-slate-500">{t('reportCard.annual')}</span>
                <span className="text-start">{t('reportCard.absentDays')}</span>
                {data.terms.map((term) => <span key={term.id}>{term.absent}</span>)}
                <span className="font-bold">{data.attendance.absent}</span>
                <span className="text-start">{t('reportCard.lateDays')}</span>
                {data.terms.map((term) => <span key={term.id}>{term.late}</span>)}
                <span className="font-bold">{data.attendance.late}</span>
                <span className="text-start">{t('reportCard.behavior')}</span>
                {data.terms.map((term) => <span key={term.id}>{level(term.behavior)}</span>)}
                <span className="font-bold">{level(data.behavior)}</span>
              </div>
            </section>
          </div>
          <div className="hidden overflow-x-auto sm:block print:block">
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr className="bg-slate-100">
                  <th className="border p-2 text-start">{t('reportCard.subject')}</th>
                  {data.terms.map((term) => <th key={term.id} className="border p-2 text-center">{term.name}</th>)}
                  <th className="border p-2 text-center">{t('reportCard.annual')}</th>
                  <th className="border p-2 text-center">{t('reportCard.result')}</th>
                </tr>
              </thead>
              <tbody>
                {data.subjects.map((s) => (
                  <tr key={s.subject.id}>
                    <td className="border p-2 font-medium">{name(s.subject)}</td>
                    {data.terms.map((term) => <td key={term.id} dir="ltr" className="border p-2 text-center">{pct(s.terms[term.id]?.percent)}</td>)}
                    <td dir="ltr" className="border p-2 text-center font-bold">{pct(s.annual)}</td>
                    <td className={`border p-2 text-center font-bold ${s.annual_result === 'fail' ? 'text-red-700 print:text-black' : ''}`}>{t(`reportCard.results.${RESULT[s.annual_result]}`)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="bg-slate-50 font-bold">
                  <td className="border p-2">{t('reportCard.average')}</td>
                  {data.terms.map((term) => <td key={term.id} dir="ltr" className="border p-2 text-center">{pct(term.average)}</td>)}
                  <td dir="ltr" className="border p-2 text-center">{pct(data.annual_average)}</td>
                  <td className="border p-2 text-center">{data.annual_average === null ? '—' : t(`reportCard.results.${data.annual_average >= data.pass_percent ? 'pass' : 'fail'}`)}</td>
                </tr>
                <tr>
                  <td className="border p-2">{t('reportCard.absentDays')}</td>
                  {data.terms.map((term) => <td key={term.id} className="border p-2 text-center">{term.absent}</td>)}
                  <td className="border p-2 text-center font-bold">{data.attendance.absent}</td>
                  <td className="border p-2" />
                </tr>
                <tr>
                  <td className="border p-2">{t('reportCard.lateDays')}</td>
                  {data.terms.map((term) => <td key={term.id} className="border p-2 text-center">{term.late}</td>)}
                  <td className="border p-2 text-center font-bold">{data.attendance.late}</td>
                  <td className="border p-2" />
                </tr>
                <tr>
                  <td className="border p-2">{t('reportCard.behavior')}</td>
                  {data.terms.map((term) => <td key={term.id} className="border p-2 text-center">{level(term.behavior)}</td>)}
                  <td className="border p-2 text-center font-bold">{level(data.behavior)}</td>
                  <td className="border p-2" />
                </tr>
              </tfoot>
            </table>
          </div>
          </>
        )}

        <p className="text-xs text-slate-500">{t('reportCard.passMark', { percent: data.pass_percent })}</p>

        <div className="flex flex-wrap items-end justify-around gap-6 pt-6 text-center text-sm">
          {signers.map((s) => (
            <div key={s.key} className="min-w-32 flex-1">
              <div className="flex h-16 items-end justify-center pb-1">{s.image && <img src={s.image} alt="" className="max-h-16 max-w-full object-contain" />}</div>
              <div className="border-t border-slate-700 pt-1"><div className="font-medium">{s.title}</div>{s.name && <div className="text-xs text-slate-600">{s.name}</div>}</div>
            </div>
          ))}
        </div>
      </article>
    </div>
  )
}
