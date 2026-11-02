import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'

const CHOICE = ['single_choice', 'multiple_choice']
const blank = (type = 'single_choice') => ({ type, text: '', options: '', is_required: true })

// New poll, survey or parent approval. The admin writes to all parents or one section; a teacher only to their sections.
export default function SurveyForm({ isAdmin, onClose, onCreated }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ type: 'poll', title: '', description: '', audience: isAdmin ? 'all' : 'classrooms', grade_from_id: '', grade_to_id: '', closes_at: '' })
  const [picked, setPicked] = useState([])
  const [questions, setQuestions] = useState([blank()])
  const [error, setError] = useState('')
  const classrooms = useQuery({ queryKey: ['behavior', 'classrooms'], queryFn: async () => (await api.get('/behavior/classrooms')).data.data })
  const grades = useQuery({ queryKey: ['grade-levels', 'all'], queryFn: async () => (await api.get('/grade-levels', { params: { per_page: 100 } })).data.data, enabled: isAdmin })
  const sortedGrades = [...(grades.data ?? [])].sort((a, b) => a.sort_order - b.sort_order)
  const gradeName = (g) => g[`name_${i18n.language}`] || g.name_ar
  const allIds = (classrooms.data ?? []).map((c) => c.id)
  const toggle = (id) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))
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
      type: form.type, title: form.title, description: form.description, closes_at: form.closes_at || null, audience: form.audience,
      ...(form.audience === 'grades' ? { grade_from_id: Number(form.grade_from_id), grade_to_id: Number(form.grade_to_id) } : {}),
      ...(form.audience === 'classrooms' ? { classroom_ids: picked } : {}),
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
        <fieldset className="space-y-2 rounded border p-3">
          <legend className="px-1 text-sm font-medium">{t('surveys.audience')}</legend>
          {isAdmin && (
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
              {['all', 'grades', 'classrooms'].map((a) => (
                <label key={a} className="flex items-center gap-1.5"><input type="radio" name="audience" checked={form.audience === a} onChange={() => setForm({ ...form, audience: a })} />{t(`surveys.audiences.${a}`)}</label>
              ))}
            </div>
          )}
          {form.audience === 'grades' && (
            <div className="grid gap-3 sm:grid-cols-2">
              <label className="block text-sm">{t('surveys.fromGrade')}
                <select required className="mt-1 w-full rounded border p-2" value={form.grade_from_id} onChange={set('grade_from_id')}>
                  <option value="">—</option>
                  {sortedGrades.map((g) => <option key={g.id} value={g.id}>{gradeName(g)}</option>)}
                </select>
              </label>
              <label className="block text-sm">{t('surveys.toGrade')}
                <select required className="mt-1 w-full rounded border p-2" value={form.grade_to_id} onChange={set('grade_to_id')}>
                  <option value="">—</option>
                  {sortedGrades.map((g) => <option key={g.id} value={g.id}>{gradeName(g)}</option>)}
                </select>
              </label>
            </div>
          )}
          {form.audience === 'classrooms' && (
            <div className="space-y-1">
              <label className="flex items-center gap-2 text-sm font-medium"><input type="checkbox" checked={allIds.length > 0 && picked.length === allIds.length} onChange={(e) => setPicked(e.target.checked ? allIds : [])} />{t('surveys.allMySections')}</label>
              <div className="flex flex-wrap gap-2">
                {(classrooms.data ?? []).map((c) => (
                  <label key={c.id} className={`flex items-center gap-1.5 rounded border px-2 py-1 text-sm ${picked.includes(c.id) ? 'border-brand bg-blue-50' : ''}`}><input type="checkbox" checked={picked.includes(c.id)} onChange={() => toggle(c.id)} />{c.name}</label>
                ))}
              </div>
              <p className="text-xs text-slate-500">{t('surveys.pickedCount', { count: picked.length })}</p>
            </div>
          )}
          {form.audience === 'all' && <p className="text-sm text-slate-600">{t('surveys.allParents')}</p>}
        </fieldset>
        <label className="block text-sm">{t('surveys.closesAt')}<input type="datetime-local" className="mt-1 w-full rounded border p-2" value={form.closes_at} onChange={set('closes_at')} /></label>

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
          <button type="submit" disabled={save.isPending || (form.audience === 'classrooms' && picked.length === 0)} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('surveys.send')}</button>
        </div>
      </form>
    </Modal>
  )
}
