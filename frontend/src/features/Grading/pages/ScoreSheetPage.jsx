import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useStudentName } from '../../Attendance/components/useStudentName'
import ResultBadge from '../components/ResultBadge'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const round = (n) => Math.round(n * 100) / 100

// One assessment: a score box per student of the classroom. An empty box means "not graded".
export default function ScoreSheetPage() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const client = useQueryClient()
  const nameOf = useStudentName()
  const [values, setValues] = useState({})
  const [message, setMessage] = useState({ kind: '', text: '' })
  const dirty = useRef(false) // unsaved typing must survive a background refetch

  const { data } = useQuery({ queryKey: ['assessment', id], queryFn: async () => (await api.get(`/assessments/${id}`)).data.data })
  const assessment = data?.assessment
  const students = data?.students ?? []

  useEffect(() => {
    if (!data || dirty.current) return
    setValues(Object.fromEntries(data.students.map((s) => [s.id, { score: s.score ?? '', status: s.status ?? '', remark: s.remark ?? '' }])))
  }, [data])

  const change = (studentId, patch) => {
    dirty.current = true
    setMessage({ kind: '', text: '' })
    setValues({ ...values, [studentId]: { ...values[studentId], ...patch } })
  }
  const edit = (studentId, field) => (e) => change(studentId, { [field]: e.target.value })
  // An absent student has no score until the makeup exam, and a retake starts from an empty box (the failing score is kept as the first attempt).
  const setStatus = (studentId, status) => change(studentId, status === 'absent' || status === 'retake' ? { status, score: '' } : { status })

  const save = useMutation({
    mutationFn: (scores) => api.put(`/assessments/${id}/scores`, { scores }),
    onSuccess: () => {
      dirty.current = false
      setMessage({ kind: 'ok', text: t('grading.saved') })
      client.invalidateQueries({ queryKey: ['assessment', id] })
      client.invalidateQueries({ queryKey: ['assessments'] })
    },
    onError: (err) => setMessage({ kind: 'error', text: err.response?.data?.message || t('admin.actions.error') }),
  })

  const submit = () => {
    const scores = students.map((s) => {
      const v = values[s.id] ?? { score: '', status: '', remark: '' }
      return { student_id: s.id, score: v.score === '' ? null : Number(v.score), status: v.status === '' ? null : v.status, remark: v.remark === '' ? null : v.remark }
    })
    if (scores.some((s) => s.score !== null && (Number.isNaN(s.score) || s.score < 0 || s.score > assessment.max_score))) {
      setMessage({ kind: 'error', text: t('grading.scoreTooHigh', { max: assessment.max_score }) })
      return
    }
    save.mutate(scores)
  }

  const publish = useMutation({
    mutationFn: () => api.post(`/assessments/${id}/${assessment.is_published ? 'unpublish' : 'publish'}`),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['assessment', id] }); client.invalidateQueries({ queryKey: ['assessments'] }) },
    onError: (err) => setMessage({ kind: 'error', text: err.response?.data?.message || t('admin.actions.error') }),
  })

  if (!assessment) return <p className="text-slate-500">{t('common.loading')}</p>

  const passPercent = data.pass_percent ?? 50
  const resultOf = (s) => {
    const v = values[s.id] ?? {}
    if (v.status === 'absent') return 'pending'
    if (v.status === 'retake' && (v.score === '' || v.score === undefined || v.score === null)) return 'retake'
    if (v.score === '' || v.score === undefined || v.score === null) return null
    return (Number(v.score) / assessment.max_score) * 100 >= passPercent ? 'pass' : 'fail'
  }
  const pending = students.filter((s) => ['absent', 'retake'].includes(values[s.id]?.status) && (values[s.id]?.status === 'absent' || values[s.id]?.score === '')).length
  const entered = students.map((s) => values[s.id]?.score).filter((v) => v !== '' && v !== undefined && v !== null)
  const average = entered.length ? round(entered.reduce((a, v) => a + Number(v), 0) / entered.length) : 0

  return (
    <section className="space-y-4 pb-24 md:pb-0">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-xl font-bold">{assessment.title}</h1>
          <p className="text-sm text-slate-600">
            {[assessment.classroom?.name, assessment.subject?.[`name_${i18n.language}`], assessment.term?.name].filter(Boolean).join(' · ')}
            {' · '}{t('grading.maxScore')}: {assessment.max_score}
          </p>
        </div>
        <InteractiveHoverButton variant="outline" type="button" className="min-h-11 w-full px-3 py-1.5 font-medium sm:min-h-0 sm:w-auto sm:font-normal" disabled={publish.isPending} onClick={() => publish.mutate()}>
          {t(assessment.is_published ? 'grading.unpublish' : 'grading.publish')}
        </InteractiveHoverButton>
      </div>

      {message.kind === 'error' && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{message.text}</p>}
      {message.kind === 'ok' && <p role="status" className="rounded bg-green-50 p-3 text-green-800">{message.text}</p>}

      {/* A phone: one card per student (number, name and result on top; the status and the score side by side, the score big; the remark under them).
          From md up the same elements are one row. */}
      {/* The theme sets rounded-*, shadow-* and bg-* without a breakpoint, so below md the list box is `contents` (no box) and the cards carry their own look. */}
      <ul className="rounded-lg bg-white shadow-sm max-md:contents md:divide-y">
        {students.map((s, i) => {
          const v = values[s.id] ?? { score: '', status: '', remark: '' }
          const result = resultOf(s)
          const first = s.first_score ?? (v.status === 'retake' && s.status !== 'retake' && s.score !== null ? s.score : null)
          return (
            <li key={s.id} className="max-md:mb-3 max-md:rounded-[26px] max-md:border max-md:bg-white max-md:shadow-[var(--shadow-flat)]">
              <div role="group" aria-label={nameOf(s)} className="grid grid-cols-2 items-center gap-2 p-3 md:flex md:flex-wrap md:gap-3">
                <div className="col-span-2 flex items-start gap-2 md:contents">
                  <span className="mt-0.5 w-6 shrink-0 text-center text-sm text-slate-500">{i + 1}</span>
                  <span className="min-w-0 flex-1 font-medium md:min-w-32 md:font-normal">
                    {nameOf(s)}
                    {first !== null && <span className="block text-xs font-normal text-slate-500">{t('studentGrades.firstAttempt', { score: first })}</span>}
                    {s.was_absent && v.status === '' && v.score === '' && (
                      <span className="mt-1 flex flex-wrap items-center gap-2 text-xs font-normal text-amber-700">
                        {t('grading.absentThatDay')}
                        <InteractiveHoverButton variant="outline" type="button" className="border-amber-400 px-2 py-0.5" onClick={() => setStatus(s.id, 'absent')}>{t('grading.markAbsent')}</InteractiveHoverButton>
                      </span>
                    )}
                  </span>
                  <span className="shrink-0 text-center md:order-1 md:w-28">{result && <ResultBadge kind={result} />}</span>
                </div>
                <select aria-label={t('grading.statusLabel')} className="min-h-12 w-full rounded border p-2 text-sm md:min-h-0 md:w-auto" value={v.status} onChange={(e) => setStatus(s.id, e.target.value)}>
                  <option value="">{t('grading.statusNormal')}</option>
                  <option value="absent">{t('grading.statusAbsent')}</option>
                  <option value="makeup">{t('grading.statusMakeup')}</option>
                  <option value="retake">{t('grading.statusRetake')}</option>
                </select>
                <input
                  type="number" inputMode="decimal" min="0" max={assessment.max_score} step="any" dir="ltr" aria-label={t('grading.score')} disabled={v.status === 'absent'}
                  className="min-h-12 w-full rounded border p-2 text-lg font-semibold disabled:bg-slate-100 md:min-h-0 md:w-24 md:text-base md:font-normal" value={v.score ?? ''} onChange={edit(s.id, 'score')}
                />
                <input
                  aria-label={t('grading.remark')} placeholder={t('grading.remark')}
                  className="col-span-2 min-h-12 w-full rounded border p-2 md:order-2 md:min-h-0 md:min-w-32 md:flex-1" value={v.remark ?? ''} onChange={edit(s.id, 'remark')}
                />
              </div>
            </li>
          )
        })}
      </ul>

      {/* On a phone the summary and Save stay on screen above the tab bar while the teacher scrolls the students. */}
      <div className="fixed inset-x-0 bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-30 flex items-center justify-between gap-3 border-t bg-white/95 p-3 backdrop-blur md:static md:flex-wrap md:border-0 md:bg-transparent md:p-0 md:backdrop-blur-none">
        <p className="min-w-0 flex-1 text-xs text-slate-600 md:text-sm">{t('grading.summary', { n: entered.length, total: students.length, avg: average, max: assessment.max_score })}
          {pending > 0 && ` · ${t('grading.pendingCount', { n: pending })}`}
        </p>
        <InteractiveHoverButton type="button" disabled={save.isPending} className="min-h-11 shrink-0 px-6 py-2 font-semibold disabled:opacity-60 md:min-h-0 md:rounded md:px-5 md:font-normal" onClick={submit}>
          {t('admin.actions.save')}
        </InteractiveHoverButton>
      </div>
    </section>
  )
}
