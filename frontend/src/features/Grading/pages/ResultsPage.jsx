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
  const results = useQuery({ queryKey: ['grading', 'results', params], queryFn: async () => (await api.get('/grading/results', { params })).data.data })

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
        <p className="text-sm text-slate-600">
          <span>{t('grading.passedFailed', { passed, failed })}</span> · <span>{t('grading.passMark', { percent: results.data.pass_percent })}</span>
        </p>
      )}
      <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-100">
            <tr>
              <th className="w-10 p-2 sm:p-3" />
              {['student', 'percent', 'result', 'gradedCount'].map((k) => <th key={k} className="p-2 text-start font-medium sm:p-3">{t(`grading.${k}`)}</th>)}
            </tr>
          </thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={r.student.id} className="border-t">
                <td className="p-2 text-center text-slate-500 sm:p-3">{i + 1}</td>
                <td className="p-2 font-medium sm:p-3">{nameOf(r.student)}</td>
                <td dir="ltr" className="p-2 text-start sm:p-3">{r.percent === null ? '—' : `${r.percent}%`}</td>
                <td className="space-x-2 p-2 sm:p-3 rtl:space-x-reverse">
                  <ResultBadge kind={r.passed === null ? 'none' : r.passed ? 'pass' : 'fail'} />
                  {r.pending > 0 && <ResultBadge kind="pending" />}
                  {r.retake > 0 && <ResultBadge kind="retake" />}
                </td>
                <td className="p-2 sm:p-3">{r.graded}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {results.isSuccess && rows.length === 0 && <p className="p-6 text-center text-slate-500">{t('admin.actions.empty')}</p>}
      </div>
    </section>
  )
}
