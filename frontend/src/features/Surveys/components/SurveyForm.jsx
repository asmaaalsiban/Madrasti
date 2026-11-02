import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'

const CHOICE = ['single_choice', 'multiple_choice']
const blank = (type = 'single_choice') => ({ type, text: '', options: '', is_required: true })

// New poll, survey or parent approval. The admin writes to all parents or one section; a teacher only to their sections.
export default function SurveyForm({ isAdmin, onClose, onCreated }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ type: 'poll', title: '', description: '', audience: isAdmin ? 'all' : 'classroom', classroom_id: '', closes_at: '' })
  const [questions, setQuestions] = useState([blank()])
  const [error, setError] = useState('')
  const classrooms = useQuery({ queryKey: ['behavior', 'classrooms'], queryFn: async () => (await api.get('/behavior/classrooms')).data.data })
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })
  const setQ = (i, patch) => setQuestions((qs) => qs.map((q, j) => (j === i ? { ...q, ...patch } : q)))

  const changeType = (e) => {
    const type = e.target.value
    setForm({ ...form, type })
    if (type === 'poll') setQuestions((qs) => [qs[0] && qs[0].type !== 'text' ? qs[0] : blank()])
    if (type === 'approval') setQuestions((qs) => qs.filter((q) => q.text))
    if (type === 'survey' && questions.length === 0) setQuestions([blank()])
  }

  const save = useMutation({
    mutationFn: () => api.post('/surveys', {
      ...form, closes_at: form.closes_at || null, classroom_id: form.audience === 'classroom' ? Number(form.classroom_id) : null,
      questions: questions.map((q) => ({ type: q.type, text: q.text, is_required: q.is_required, ...(CHOICE.includes(q.type) ? { options: q.options.split('\n').map((o) => o.trim()).filter(Boolean) } : {}) })),
    }),
    onSuccess: (r) => { client.invalidateQueries({ queryKey: ['surveys'] }); onCreated(r.data.data) },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || t('admin.actions.error')),
  })

  const types = form.type === 'poll' ? ['single_choice', 'multiple_choice', 'yes_no'] : ['single_choice', 'multiple_choice', 'yes_no', 'text']
  return (
    <Modal open size="lg" title={t('surveys.new')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('surveys.type')}
          <select className="mt-1 w-full rounded border p-2" value={form.type} onChange={changeType}>
            {['poll', 'survey', 'approval'].map((x) => <option key={x} value={x}>{t(`surveys.types.${x}`)}</option>)}
          </select>
        </label>
        <p className="text-xs text-slate-500">{t(`surveys.typeHelp.${form.type}`)}</p>
        <label className="block text-sm">{t('surveys.fieldTitle')}<input required className="mt-1 w-full rounded border p-2" value={form.title} onChange={set('title')} /></label>
        <label className="block text-sm">{t('surveys.description')}<textarea rows={2} className="mt-1 w-full rounded border p-2" value={form.description} onChange={set('description')} /></label>
        <div className="grid gap-3 sm:grid-cols-2">
          {isAdmin && (
            <label className="block text-sm">{t('surveys.audience')}
              <select className="mt-1 w-full rounded border p-2" value={form.audience} onChange={set('audience')}>
                <option value="all">{t('surveys.allParents')}</option>
                <option value="classroom">{t('surveys.oneClassroom')}</option>
              </select>
            </label>
          )}
          {form.audience === 'classroom' && (
            <label className="block text-sm">{t('academic.fields.classroom')}
              <select required className="mt-1 w-full rounded border p-2" value={form.classroom_id} onChange={set('classroom_id')}>
                <option value="">—</option>
                {(classrooms.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </label>
          )}
          <label className="block text-sm">{t('surveys.closesAt')}<input type="datetime-local" className="mt-1 w-full rounded border p-2" value={form.closes_at} onChange={set('closes_at')} /></label>
        </div>

        <div className="space-y-3">
          <h3 className="font-bold">{t('surveys.questions')}{form.type === 'approval' && <span className="ms-2 text-xs font-normal text-slate-500">{t('surveys.optional')}</span>}</h3>
          {questions.map((q, i) => (
            <div key={i} className="space-y-2 rounded border bg-slate-50 p-3">
              <div className="flex flex-wrap items-center gap-2">
                <select aria-label={t('surveys.questionType')} className="rounded border p-2" value={q.type} onChange={(e) => setQ(i, { type: e.target.value })}>
                  {types.map((x) => <option key={x} value={x}>{t(`surveys.qtypes.${x}`)}</option>)}
                </select>
                <input aria-label={t('surveys.questionText')} required className="min-w-0 flex-1 rounded border p-2" placeholder={t('surveys.questionText')} value={q.text} onChange={(e) => setQ(i, { text: e.target.value })} />
                {form.type !== 'poll' && <button type="button" className="rounded border border-red-300 px-2 py-1 text-sm text-red-700" onClick={() => setQuestions((qs) => qs.filter((_, j) => j !== i))}>{t('admin.actions.delete')}</button>}
              </div>
              {CHOICE.includes(q.type) && (
                <label className="block text-sm">{t('surveys.options')}
                  <textarea required rows={3} className="mt-1 w-full rounded border p-2" placeholder={t('surveys.optionsHelp')} value={q.options} onChange={(e) => setQ(i, { options: e.target.value })} />
                </label>
              )}
              {form.type !== 'poll' && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={q.is_required} onChange={(e) => setQ(i, { is_required: e.target.checked })} />{t('surveys.required')}</label>}
            </div>
          ))}
          {form.type !== 'poll' && <button type="button" className="rounded border px-3 py-1.5 text-sm text-brand" onClick={() => setQuestions((qs) => [...qs, blank('text')])}>{t('surveys.addQuestion')}</button>}
        </div>

        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('surveys.send')}</button>
        </div>
      </form>
    </Modal>
  )
}
