import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import FormField from '../../../components/shared/FormField'
import Modal from '../../../components/shared/Modal'
import { DateInput } from '../../../components/shared/DateInput'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const TYPES = ['quiz', 'exam', 'assignment', 'project', 'participation']
const inputClass = 'w-full rounded border p-2'
const EMPTY = { title: '', type: 'quiz', max_score: '', weight: '', held_on: '' }

// The teacher's assessments (quizzes, exams...) of one classroom + subject in one term.
export default function AssessmentsPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const base = useLocation().pathname.startsWith('/admin') ? '/admin/grades' : '/teacher/grades'
  // The choice lives in the address (?classroom=&subject=&term=), so coming back from a score sheet lands on the same one.
  const [search, setSearch] = useSearchParams()
  const [editing, setEditing] = useState(null) // row | {} (new) | null
  const [form, setForm] = useState(EMPTY)
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [toDelete, setToDelete] = useState(null)
  const [notice, setNotice] = useState('')

  const options = useQuery({ queryKey: ['grading', 'options'], queryFn: async () => (await api.get('/grading/options')).data.data })
  const assignments = options.data?.assignments ?? []
  const terms = options.data?.terms ?? []
  const classrooms = [...new Map(assignments.map((a) => [a.classroom.id, a.classroom])).values()]
  const classroomId = classrooms.find((c) => c.id === Number(search.get('classroom')))?.id ?? classrooms[0]?.id
  const subjects = assignments.filter((a) => a.classroom.id === classroomId).map((a) => a.subject)
  const subjectId = subjects.find((s) => s.id === Number(search.get('subject')))?.id ?? subjects[0]?.id
  const termId = terms.find((x) => x.id === Number(search.get('term')))?.id ?? terms[0]?.id
  const params = classroomId && subjectId && termId ? { classroom_id: classroomId, subject_id: subjectId, term_id: termId } : null

  // replace: changing a filter must not fill the browser history, so "back" leaves the page in one step.
  const choose = (change) => setSearch({ classroom: classroomId, subject: subjectId, term: termId, ...change }, { replace: true })

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
      <div className="space-y-3 sm:flex sm:items-center sm:justify-between sm:space-y-0">
        <h1 className="text-xl font-bold">{t('grading.title')}</h1>
        {/* On a phone the two main buttons sit under the title, side by side and big enough to hit. */}
        <div className="grid grid-cols-2 gap-2 sm:flex sm:items-center">
          <InteractiveHoverButton type="button" className="min-h-11 px-4 py-1.5 font-semibold disabled:opacity-60 sm:order-2 sm:min-h-0 sm:font-normal" disabled={params === null} onClick={() => openForm(null)}>
            {t('grading.add')}
          </InteractiveHoverButton>
          {params && (
            <Link className="flex min-h-11 items-center justify-center rounded border px-3 py-1.5 font-semibold text-brand sm:order-1 sm:min-h-0 sm:font-normal sm:text-inherit" to={`${base}/results?${new URLSearchParams({ classroom: params.classroom_id, subject: params.subject_id, term: params.term_id })}`}>
              {t('grading.results')}
            </Link>
          )}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:flex sm:flex-wrap sm:items-end">
        <label className="text-sm">
          {t('grading.classroom')}
          <select className="mt-1 block w-full rounded border p-2 sm:w-auto" value={classroomId ?? ''} onChange={(e) => choose({ classroom: e.target.value, subject: '' })}>
            {classrooms.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </label>
        <label className="text-sm">
          {t('grading.subject')}
          <select className="mt-1 block w-full rounded border p-2 sm:w-auto" value={subjectId ?? ''} onChange={(e) => choose({ subject: e.target.value })}>
            {subjects.map((s) => <option key={s.id} value={s.id}>{s[`name_${i18n.language}`]}</option>)}
          </select>
        </label>
        <label className="col-span-2 text-sm sm:col-span-1">
          {t('grading.term')}
          <select className="mt-1 block w-full rounded border p-2 sm:w-auto" value={termId ?? ''} onChange={(e) => choose({ term: e.target.value })}>
            {terms.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
          </select>
        </label>
      </div>

      {notice && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{notice}</p>}
      <p className={`text-sm ${used > 100 ? 'text-red-700' : 'text-slate-600'}`}>{t('grading.weightsUsed', { used: Math.round(used * 100) / 100 })}</p>

      {/* A phone: one card per assessment. The title, its type and the status on top, the details in a grid, the actions at the bottom. */}
      <ul className="space-y-3 md:hidden" data-testid="assessment-cards">
        {rows.map((r) => (
          <li key={r.id} className="rounded-xl border bg-white p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <h2 className="break-words text-base font-bold">{r.title}</h2>
                <p className="text-xs text-slate-500">{t(`grading.types.${r.type}`)}</p>
              </div>
              <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${r.is_published ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-700'}`}>
                {t(r.is_published ? 'grading.published' : 'grading.draft')}
              </span>
            </div>
            <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
              <div className="rounded-lg bg-slate-50 p-2"><dt className="text-xs text-slate-500">{t('grading.dateCol')}</dt><dd dir="ltr" className="text-start font-medium">{r.held_on ?? '—'}</dd></div>
              <div className="rounded-lg bg-slate-50 p-2"><dt className="text-xs text-slate-500">{t('grading.maxCol')}</dt><dd className="font-medium">{r.max_score}</dd></div>
              <div className="rounded-lg bg-slate-50 p-2"><dt className="text-xs text-slate-500">{t('grading.weightCol')}</dt><dd className="font-medium">{r.weight}%</dd></div>
              <div className="rounded-lg bg-slate-50 p-2">
                <dt className="text-xs text-slate-500">{t('grading.progress')}</dt>
                <dd className="font-medium"><span dir="ltr" className="inline-block">{r.scored} / {r.students}</span></dd>
                {r.absent > 0 && <dd className="text-xs text-amber-700">{t('grading.pendingCount', { n: r.absent })}</dd>}
              </div>
            </dl>
            <div className="mt-3 grid grid-cols-2 gap-2">
              <Link className="col-span-2 flex min-h-11 items-center justify-center rounded-lg bg-brand px-3 font-semibold text-white" to={`${base}/${r.id}`}>{t('grading.enterScores')}</Link>
              <button type="button" className="min-h-11 rounded-lg border px-3 font-medium text-slate-700" onClick={() => run(() => api.post(`/assessments/${r.id}/${r.is_published ? 'unpublish' : 'publish'}`))}>
                {t(r.is_published ? 'grading.unpublish' : 'grading.publish')}
              </button>
              <button type="button" className="min-h-11 rounded-lg border px-3 font-medium text-brand" onClick={() => openForm(r)}>{t('admin.actions.edit')}</button>
              {!r.is_published && <button type="button" className="col-span-2 min-h-11 rounded-lg bg-red-50 px-3 font-medium text-red-600" onClick={() => setToDelete(r)}>{t('admin.actions.delete')}</button>}
            </div>
          </li>
        ))}
      </ul>

      {/* A tablet and a computer keep the table (in a box that scrolls sideways when it must); tighter cells on a tablet. */}
      <div className="hidden overflow-x-auto rounded-lg bg-white shadow-sm md:block">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-100">
            <tr>
              {['titleCol', 'typeCol', 'dateCol', 'maxCol', 'weightCol', 'progress', 'status'].map((k) => <th key={k} className="p-2 text-start font-medium lg:p-3">{t(`grading.${k}`)}</th>)}
              <th className="p-2 text-start font-medium lg:p-3">{t('admin.actions.actions')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-t">
                <td className="p-2 font-medium lg:p-3">{r.title}</td>
                <td className="p-2 lg:p-3">{t(`grading.types.${r.type}`)}</td>
                <td dir="ltr" className="whitespace-nowrap p-2 text-start lg:p-3">{r.held_on ?? '—'}</td>
                <td className="p-2 lg:p-3">{r.max_score}</td>
                <td className="p-2 lg:p-3">{r.weight}%</td>
                <td className="p-2 lg:p-3">
                  <span dir="ltr" className="inline-block">{r.scored} / {r.students}</span>
                  {r.absent > 0 && <span className="block text-xs text-amber-700">{t('grading.pendingCount', { n: r.absent })}</span>}
                </td>
                <td className="p-2 lg:p-3">
                  <span className={`rounded px-2 py-0.5 text-xs ${r.is_published ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-700'}`}>
                    {t(r.is_published ? 'grading.published' : 'grading.draft')}
                  </span>
                </td>
                <td className="p-2 lg:p-3">
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
      </div>
      {list.isSuccess && rows.length === 0 && <p className="rounded-lg bg-white p-6 text-center text-slate-500 shadow-sm">{t('admin.actions.empty')}</p>}

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
            <DateInput className={inputClass} value={form.held_on} onChange={set('held_on')} />
          </FormField>
          <FormFieldErrors errors={errors} names={['term_id', 'subject_id', 'classroom_id']} />
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={() => setEditing(null)}>{t('admin.actions.cancel')}</button>
            <InteractiveHoverButton type="submit" disabled={save.isPending} className="px-3 py-1.5 disabled:opacity-60">{t('admin.actions.save')}</InteractiveHoverButton>
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
