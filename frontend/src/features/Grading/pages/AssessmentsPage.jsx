import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import FormField from '../../../components/shared/FormField'
import Modal from '../../../components/shared/Modal'

const TYPES = ['quiz', 'exam', 'assignment', 'project', 'participation']
const inputClass = 'w-full rounded border p-2'
const EMPTY = { title: '', type: 'quiz', max_score: '', weight: '', held_on: '' }

// The teacher's assessments (quizzes, exams...) of one classroom + subject in one term.
export default function AssessmentsPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const base = useLocation().pathname.startsWith('/admin') ? '/admin/grades' : '/teacher/grades'
  const [pick, setPick] = useState(0)
  const [termId, setTermId] = useState('')
  const [editing, setEditing] = useState(null) // row | {} (new) | null
  const [form, setForm] = useState(EMPTY)
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [toDelete, setToDelete] = useState(null)
  const [notice, setNotice] = useState('')

  const options = useQuery({ queryKey: ['grading', 'options'], queryFn: async () => (await api.get('/grading/options')).data.data })
  const assignment = options.data?.assignments[pick]
  const term = termId || options.data?.terms[0]?.id
  const params = assignment && term ? { classroom_id: assignment.classroom.id, subject_id: assignment.subject.id, term_id: Number(term) } : null

  const list = useQuery({
    queryKey: ['assessments', params],
    queryFn: async () => (await api.get('/assessments', { params })).data.data,
    enabled: params !== null,
  })
  const refresh = () => client.invalidateQueries({ queryKey: ['assessments'] })

  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...params, title: form.title, type: form.type, max_score: form.max_score, weight: form.weight, held_on: form.held_on || null,
      }
      return editing.id ? api.put(`/assessments/${editing.id}`, body) : api.post('/assessments', body)
    },
    onSuccess: () => { setEditing(null); refresh() },
    onError: (err) => {
      const fieldErrors = err.response?.data?.errors
      setErrors(fieldErrors ?? {})
      setFormError(fieldErrors ? '' : err.response?.data?.message || t('admin.actions.error'))
    },
  })

  const run = async (action) => {
    setNotice('')
    try { await action(); refresh() } catch (err) { setNotice(err.response?.data?.message || t('admin.actions.error')) }
  }

  const openForm = (row) => {
    setErrors({})
    setFormError('')
    setForm(row?.id ? { title: row.title, type: row.type, max_score: row.max_score, weight: row.weight, held_on: row.held_on ?? '' } : EMPTY)
    setEditing(row ?? {})
  }
  const set = (name) => (e) => setForm({ ...form, [name]: e.target.value })

  if (options.isSuccess && options.data.assignments.length === 0) {
    return <p className="rounded bg-white p-4 text-slate-600">{t('grading.noAssignments')}</p>
  }

  const rows = list.data ?? []
  const used = rows.reduce((sum, r) => sum + Number(r.weight), 0)

  return (
    <section className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">{t('grading.title')}</h1>
        <button type="button" className="rounded bg-brand px-4 py-1.5 text-white" disabled={params === null} onClick={() => openForm(null)}>
          {t('grading.add')}
        </button>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <label className="text-sm">
          {t('grading.classroomSubject')}
          <select className="mt-1 block rounded border p-2" value={pick} onChange={(e) => setPick(Number(e.target.value))}>
            {(options.data?.assignments ?? []).map((a, i) => (
              <option key={`${a.classroom.id}-${a.subject.id}`} value={i}>{a.classroom.name} · {a.subject[`name_${i18n.language}`]}</option>
            ))}
          </select>
        </label>
        <label className="text-sm">
          {t('grading.term')}
          <select className="mt-1 block rounded border p-2" value={term ?? ''} onChange={(e) => setTermId(e.target.value)}>
            {(options.data?.terms ?? []).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
          </select>
        </label>
      </div>

      {notice && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{notice}</p>}
      <p className={`text-sm ${used > 100 ? 'text-red-700' : 'text-slate-600'}`}>{t('grading.weightsUsed', { used: Math.round(used * 100) / 100 })}</p>

      <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-100">
            <tr>
              {['titleCol', 'typeCol', 'dateCol', 'maxCol', 'weightCol', 'progress', 'status'].map((k) => <th key={k} className="p-2 text-start font-medium sm:p-3">{t(`grading.${k}`)}</th>)}
              <th className="p-2 text-start font-medium sm:p-3">{t('admin.actions.actions')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-t">
                <td className="p-2 font-medium sm:p-3">{r.title}</td>
                <td className="p-2 sm:p-3">{t(`grading.types.${r.type}`)}</td>
                <td dir="ltr" className="p-2 text-start sm:p-3">{r.held_on ?? '—'}</td>
                <td className="p-2 sm:p-3">{r.max_score}</td>
                <td className="p-2 sm:p-3">{r.weight}%</td>
                <td className="p-2 sm:p-3">{r.scored} / {r.students}</td>
                <td className="p-2 sm:p-3">
                  <span className={`rounded px-2 py-0.5 text-xs ${r.is_published ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-700'}`}>
                    {t(r.is_published ? 'grading.published' : 'grading.draft')}
                  </span>
                </td>
                <td className="p-2 sm:p-3">
                  <div className="flex flex-wrap gap-3">
                    <Link className="text-brand underline" to={`${base}/${r.id}`}>{t('grading.enterScores')}</Link>
                    <button type="button" className="text-slate-700 underline" onClick={() => run(() => api.post(`/assessments/${r.id}/${r.is_published ? 'unpublish' : 'publish'}`))}>
                      {t(r.is_published ? 'grading.unpublish' : 'grading.publish')}
                    </button>
                    <button type="button" className="text-brand" onClick={() => openForm(r)}>{t('admin.actions.edit')}</button>
                    {!r.is_published && <button type="button" className="text-red-600" onClick={() => setToDelete(r)}>{t('admin.actions.delete')}</button>}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {list.isSuccess && rows.length === 0 && <p className="p-6 text-center text-slate-500">{t('admin.actions.empty')}</p>}
      </div>

      <Modal open={editing !== null} title={t('grading.formTitle')} onClose={() => setEditing(null)}>
        <form onSubmit={(e) => { e.preventDefault(); save.mutate() }} className="space-y-3">
          {formError && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{formError}</p>}
          <FormField label={t('grading.titleCol')} error={errors.title?.[0]}>
            <input className={inputClass} value={form.title} onChange={set('title')} />
          </FormField>
          <FormField label={t('grading.typeCol')} error={errors.type?.[0]}>
            <select className={inputClass} value={form.type} onChange={set('type')}>
              {TYPES.map((x) => <option key={x} value={x}>{t(`grading.types.${x}`)}</option>)}
            </select>
          </FormField>
          <FormField label={t('grading.maxScore')} error={errors.max_score?.[0]}>
            <input className={inputClass} type="number" min="0" step="any" dir="ltr" value={form.max_score} onChange={set('max_score')} />
          </FormField>
          <FormField label={t('grading.weight')} error={errors.weight?.[0]} hint={t('grading.weightHint')}>
            <input className={inputClass} type="number" min="0" max="100" step="any" dir="ltr" value={form.weight} onChange={set('weight')} />
          </FormField>
          <FormField label={t('grading.date')} error={errors.held_on?.[0]}>
            <input className={inputClass} type="date" dir="ltr" value={form.held_on} onChange={set('held_on')} />
          </FormField>
          <FormFieldErrors errors={errors} names={['term_id', 'subject_id', 'classroom_id']} />
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={() => setEditing(null)}>{t('admin.actions.cancel')}</button>
            <button type="submit" disabled={save.isPending} className="rounded bg-brand px-3 py-1.5 text-white disabled:opacity-60">{t('admin.actions.save')}</button>
          </div>
        </form>
      </Modal>

      <ConfirmDialog
        open={toDelete !== null}
        message={t('admin.actions.confirmDelete')}
        onCancel={() => setToDelete(null)}
        onConfirm={() => { const row = toDelete; setToDelete(null); run(() => api.delete(`/assessments/${row.id}`)) }}
      />
    </section>
  )
}

// Errors of fields the form does not show (e.g. the term does not belong to the classroom's year).
function FormFieldErrors({ errors, names }) {
  const messages = names.flatMap((n) => errors[n] ?? [])
  return messages.length ? <p role="alert" className="text-sm text-red-600">{messages.join(' ')}</p> : null
}
