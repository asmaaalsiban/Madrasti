import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useSchool } from '../../../tenant/useSchool'

const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`)
const RESULT = { pass: 'pass', fail: 'fail', pending: 'pending', none: 'none' }

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
        <button type="button" className="rounded bg-brand px-4 py-2 text-white" onClick={() => window.print()}>{t('reportCard.print')}</button>
      </div>

      <article className="print-area mx-auto max-w-3xl space-y-5 rounded-lg border bg-white p-6 text-slate-900 shadow-sm print:max-w-none print:rounded-none print:border-0 print:p-8 print:shadow-none" aria-label={t('reportCard.title')}>
        <header className="flex items-center gap-4 border-b-2 border-brand pb-3">
          {school?.logo_url && <img src={school.logo_url} alt="" className="h-16 w-16 object-contain" />}
          <div className="flex-1">
            <h2 className="text-xl font-bold">{school?.name}</h2>
            <p className="text-lg">{t('reportCard.title')}{data.year ? ` · ${data.year.name}` : ''}</p>
          </div>
        </header>

        <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
          <div><dt className="text-slate-500">{t('reportCard.student')}</dt><dd className="font-bold">{name(data.student)}</dd></div>
          <div><dt className="text-slate-500">{t('reportCard.number')}</dt><dd dir="ltr" className="text-start font-medium">{data.student.student_number}</dd></div>
          <div><dt className="text-slate-500">{t('reportCard.classroom')}</dt><dd className="font-medium">{[data.student.grade && name(data.student.grade), data.student.classroom].filter(Boolean).join(' · ') || '—'}</dd></div>
        </dl>

        {noGrades ? <p className="rounded bg-slate-50 p-4 text-slate-600">{t('reportCard.empty')}</p> : (
          <div className="overflow-x-auto">
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
