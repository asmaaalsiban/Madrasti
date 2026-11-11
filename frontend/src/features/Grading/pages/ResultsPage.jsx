import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import ExportButton from '../../../components/shared/ExportButton'
import { api } from '../../../api/client'
import { useStudentName } from '../../Attendance/components/useStudentName'
import ResultBadge from '../components/ResultBadge'

// Pass / fail of every student of a classroom in one subject and term (weighted percentage of the graded assessments).
export default function ResultsPage() {
  const { t, i18n } = useTranslation()
  const nameOf = useStudentName()
  const [search] = useSearchParams()
  const params = { classroom_id: Number(search.get('classroom')), subject_id: Number(search.get('subject')), term_id: Number(search.get('term')) }

  const options = useQuery({ queryKey: ['grading', 'options'], queryFn: async () => (await api.get('/grading/options')).data.data })
  const results = useQuery({ queryKey: ['grading', 'results', params], queryFn: async () => (await api.get('/grading/results', { params })).data.data, enabled: Object.values(params).every((id) => id > 0) })

  const assignment = options.data?.assignments.find((a) => a.classroom.id === params.classroom_id && a.subject.id === params.subject_id)
  const title = assignment ? `${assignment.classroom.name} · ${assignment.subject[`name_${i18n.language}`]}` : ''
  const rows = results.data?.students ?? []
  const passed = rows.filter((r) => r.passed === true).length
  const failed = rows.filter((r) => r.passed === false).length

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('grading.resultsTitle', { name: title })}</h1>
        <ExportButton path="/grading/results/export" params={params} fallbackName="results.xlsx" />
      </div>
      {results.data && (
        <>
          {/* A phone: the passed and failed counts as two boxes; a tablet and a computer: one line of text. */}
          <div className="grid grid-cols-2 gap-2 md:hidden" data-testid="results-summary">
            <div className="rounded-xl bg-green-50 p-3 text-center"><div className="text-2xl font-bold text-green-800">{passed}</div><div className="text-xs text-green-800">{t('grading.pass')}</div></div>
            <div className="rounded-xl bg-red-50 p-3 text-center"><div className="text-2xl font-bold text-red-800">{failed}</div><div className="text-xs text-red-800">{t('grading.fail')}</div></div>
          </div>
          <p className="text-center text-xs text-slate-500 md:text-start md:text-sm md:text-slate-600">
            <span className="hidden md:inline">{t('grading.passedFailed', { passed, failed })}</span><span className="hidden md:inline"> · </span><span>{t('grading.passMark', { percent: results.data.pass_percent })}</span>
          </p>
        </>
      )}

      {/* A phone: one card per student: rank, name, the percentage big, the badges. */}
      <ul className="space-y-2 md:hidden" data-testid="result-cards">
        {rows.map((r, i) => (
          <li key={r.student.id} className="flex items-center gap-3 rounded-xl border bg-white p-3 shadow-sm">
            <span className="w-6 shrink-0 text-center text-sm text-slate-500">{i + 1}</span>
            <div className="min-w-0 flex-1">
              <p className="font-bold">{nameOf(r.student)}</p>
              <div className="mt-1 flex flex-wrap gap-1">
                <ResultBadge kind={r.passed === null ? 'none' : r.passed ? 'pass' : 'fail'} />
                {r.pending > 0 && <ResultBadge kind="pending" />}
                {r.retake > 0 && <ResultBadge kind="retake" />}
              </div>
            </div>
            <div className="shrink-0 text-end">
              <div dir="ltr" className={`text-xl font-bold ${r.passed === false ? 'text-red-700' : ''}`}>{r.percent === null ? '—' : `${r.percent}%`}</div>
              <div className="text-[11px] text-slate-500">{t('grading.gradedCount')}: {r.graded}</div>
            </div>
          </li>
        ))}
      </ul>

      <div className="hidden overflow-x-auto rounded-lg bg-white shadow-sm md:block">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-100">
            <tr>
              <th className="w-10 p-2 lg:p-3" />
              {['student', 'percent', 'result', 'gradedCount'].map((k) => <th key={k} className="p-2 text-start font-medium lg:p-3">{t(`grading.${k}`)}</th>)}
            </tr>
          </thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={r.student.id} className="border-t">
                <td className="p-2 text-center text-slate-500 lg:p-3">{i + 1}</td>
                <td className="p-2 font-medium lg:p-3">{nameOf(r.student)}</td>
                <td dir="ltr" className="p-2 text-start lg:p-3">{r.percent === null ? '—' : `${r.percent}%`}</td>
                <td className="space-x-2 p-2 lg:p-3 rtl:space-x-reverse">
                  <ResultBadge kind={r.passed === null ? 'none' : r.passed ? 'pass' : 'fail'} />
                  {r.pending > 0 && <ResultBadge kind="pending" />}
                  {r.retake > 0 && <ResultBadge kind="retake" />}
                </td>
                <td className="p-2 lg:p-3">{r.graded}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {results.isSuccess && rows.length === 0 && <p className="rounded-lg bg-white p-6 text-center text-slate-500 shadow-sm">{t('admin.actions.empty')}</p>}
    </section>
  )
}
