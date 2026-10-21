import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useStudentName } from '../../Attendance/components/useStudentName'
import ResultBadge from '../components/ResultBadge'

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
  // An absent student has no score until the makeup exam.
  const setStatus = (studentId, status) => change(studentId, status === 'absent' ? { status, score: '' } : { status })

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
    if (v.score === '' || v.score === undefined || v.score === null) return null
    return (Number(v.score) / assessment.max_score) * 100 >= passPercent ? 'pass' : 'fail'
  }
  const pending = students.filter((s) => values[s.id]?.status === 'absent').length
  const entered = students.map((s) => values[s.id]?.score).filter((v) => v !== '' && v !== undefined && v !== null)
  const average = entered.length ? round(entered.reduce((a, v) => a + Number(v), 0) / entered.length) : 0

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-xl font-bold">{assessment.title}</h1>
          <p className="text-sm text-slate-600">
            {[assessment.classroom?.name, assessment.subject?.[`name_${i18n.language}`], assessment.term?.name].filter(Boolean).join(' · ')}
            {' · '}{t('grading.maxScore')}: {assessment.max_score}
          </p>
        </div>
        <button type="button" className="rounded border px-3 py-1.5" disabled={publish.isPending} onClick={() => publish.mutate()}>
          {t(assessment.is_published ? 'grading.unpublish' : 'grading.publish')}
        </button>
      </div>

      {message.kind === 'error' && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{message.text}</p>}
      {message.kind === 'ok' && <p role="status" className="rounded bg-green-50 p-3 text-green-800">{message.text}</p>}

      <ul className="divide-y rounded-lg bg-white shadow-sm">
        {students.map((s, i) => {
          const v = values[s.id] ?? { score: '', status: '', remark: '' }
          const result = resultOf(s)
          return (
            <li key={s.id} role="group" aria-label={nameOf(s)} className="flex flex-wrap items-center gap-3 p-3">
              <span className="w-6 text-center text-sm text-slate-500">{i + 1}</span>
              <span className="min-w-32 flex-1">
                {nameOf(s)}
                {s.was_absent && v.status === '' && v.score === '' && (
                  <span className="mt-1 flex flex-wrap items-center gap-2 text-xs text-amber-700">
                    {t('grading.absentThatDay')}
                    <button type="button" className="rounded border border-amber-400 px-2 py-0.5" onClick={() => setStatus(s.id, 'absent')}>{t('grading.markAbsent')}</button>
                  </span>
                )}
              </span>
              <select aria-label={t('grading.statusLabel')} className="rounded border p-2 text-sm" value={v.status} onChange={(e) => setStatus(s.id, e.target.value)}>
                <option value="">{t('grading.statusNormal')}</option>
                <option value="absent">{t('grading.statusAbsent')}</option>
                <option value="makeup">{t('grading.statusMakeup')}</option>
              </select>
              <input
                type="number" min="0" max={assessment.max_score} step="any" dir="ltr" aria-label={t('grading.score')} disabled={v.status === 'absent'}
                className="w-24 rounded border p-2 disabled:bg-slate-100" value={v.score ?? ''} onChange={edit(s.id, 'score')}
              />
              <span className="w-28 text-center">{result && <ResultBadge kind={result} />}</span>
              <input
                aria-label={t('grading.remark')} placeholder={t('grading.remark')}
                className="min-w-32 flex-1 rounded border p-2" value={v.remark ?? ''} onChange={edit(s.id, 'remark')}
              />
            </li>
          )
        })}
      </ul>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-slate-600">{t('grading.summary', { n: entered.length, total: students.length, avg: average, max: assessment.max_score })}
          {pending > 0 && ` · ${t('grading.pendingCount', { n: pending })}`}
        </p>
        <button type="button" disabled={save.isPending} className="rounded bg-brand px-5 py-2 text-white disabled:opacity-60" onClick={submit}>
          {t('admin.actions.save')}
        </button>
      </div>
    </section>
  )
}
