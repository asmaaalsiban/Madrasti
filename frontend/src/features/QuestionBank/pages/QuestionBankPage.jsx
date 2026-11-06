import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import Modal from '../../../components/shared/Modal'
import RichText from '../components/RichText'
import QuestionImage from '../components/QuestionImage'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const blankOption = (correct = false) => ({ body: '', is_correct: correct })

// One optional picture (of the question, or of its explanation): keep, replace or remove it.
function ImageField({ slot, question, files, setFiles, removed, setRemoved }) {
  const { t } = useTranslation()
  const has = slot === 'question' ? question?.has_image : question?.has_explanation_image
  return (
    <div className="space-y-1 text-sm">
      <p className="font-bold">{t(slot === 'question' ? 'questionBank.questionImage' : 'questionBank.explanationImage')} <span className="font-normal text-slate-500">({t('questionBank.optional')})</span></p>
      {has && !removed[slot] && !files[slot] && (
        <div className="flex items-end gap-3"><QuestionImage path={`/question-bank/questions/${question.id}/image/${slot}`} alt={t('questionBank.imageAlt')} /><button type="button" className="text-red-700 underline" onClick={() => setRemoved({ ...removed, [slot]: true })}>{t('questionBank.removeImage')}</button></div>
      )}
      <input type="file" accept="image/png,image/jpeg,image/webp" aria-label={t(slot === 'question' ? 'questionBank.questionImage' : 'questionBank.explanationImage')} onChange={(e) => setFiles({ ...files, [slot]: e.target.files?.[0] ?? null })} />
    </div>
  )
}

function QuestionDialog({ lessonId, question, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [body, setBody] = useState(question?.body ?? '')
  const [explanation, setExplanation] = useState(question?.explanation ?? '')
  const [options, setOptions] = useState(question ? question.options.map((o) => ({ body: o.body, is_correct: o.is_correct })) : [blankOption(true), blankOption(), blankOption()])
  const [error, setError] = useState('')
  const [files, setFiles] = useState({ question: null, explanation: null })
  const [removed, setRemoved] = useState({ question: false, explanation: false })
  const save = useMutation({
    mutationFn: async () => {
      const payload = { body, explanation: explanation || null, options }
      const res = await (question ? api.put(`/question-bank/questions/${question.id}`, payload) : api.post(`/question-bank/lessons/${lessonId}/questions`, payload))
      const id = res.data.data.id
      for (const slot of ['question', 'explanation']) {
        if (files[slot]) {
          const form = new FormData()
          form.append('image', files[slot])
          await api.post(`/question-bank/questions/${id}/image/${slot}`, form)
        } else if (removed[slot]) {
          await api.delete(`/question-bank/questions/${id}/image/${slot}`)
        }
      }
      return res
    },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['question-bank'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const setOption = (i, patch) => setOptions(options.map((o, j) => (j === i ? { ...o, ...patch } : patch.is_correct ? { ...o, is_correct: false } : o)))
  return (
    <Modal open size="lg" title={question ? t('questionBank.editQuestion') : t('questionBank.newQuestion')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('questionBank.questionText')}<textarea required rows={3} dir="auto" className="mt-1 w-full rounded border p-2" value={body} onChange={(e) => setBody(e.target.value)} /><span className="text-xs text-slate-500">{t('questionBank.mathHint')}</span></label>
        {body.includes('$') && <p className="rounded bg-slate-50 p-2 text-sm" aria-label={t('questionBank.preview')}><RichText text={body} /></p>}
        <ImageField slot="question" question={question} files={files} setFiles={setFiles} removed={removed} setRemoved={setRemoved} />
        <fieldset className="space-y-2">
          <legend className="text-sm font-bold">{t('questionBank.options')} <span className="font-normal text-slate-500">({t('questionBank.pickCorrect')})</span></legend>
          {options.map((o, i) => (
            <div key={i} className="flex items-center gap-2">
              <input type="radio" name="correct" aria-label={t('questionBank.correctOne', { n: i + 1 })} checked={o.is_correct} onChange={() => setOption(i, { is_correct: true })} />
              <input required dir="auto" aria-label={t('questionBank.optionN', { n: i + 1 })} className="min-w-0 flex-1 rounded border p-2" value={o.body} onChange={(e) => setOption(i, { body: e.target.value })} />
              {options.length > 2 && !question && <button type="button" aria-label={t('admin.actions.delete')} className="text-red-700" onClick={() => setOptions(options.filter((_, j) => j !== i))}><Trash2 size={16} aria-hidden /></button>}
            </div>
          ))}
          {options.length < 6 && !question && <button type="button" className="text-sm text-brand underline" onClick={() => setOptions([...options, blankOption()])}>{t('questionBank.addOption')}</button>}
        </fieldset>
        <label className="block text-sm">{t('questionBank.explanation')}<textarea rows={3} dir="auto" className="mt-1 w-full rounded border p-2" value={explanation} onChange={(e) => setExplanation(e.target.value)} /><span className="text-xs text-slate-500">{t('questionBank.explanationHint')}</span></label>
        <ImageField slot="explanation" question={question} files={files} setFiles={setFiles} removed={removed} setRemoved={setRemoved} />
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending || options.filter((o) => o.is_correct).length !== 1} className="rounded bg-brand px-5 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

function Questions({ lesson }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [dialog, setDialog] = useState(null)
  const [removing, setRemoving] = useState(null)
  const { data } = useQuery({ queryKey: ['question-bank', 'questions', lesson.id], queryFn: async () => (await api.get(`/question-bank/lessons/${lesson.id}/questions`)).data.data })
  const del = useMutation({ mutationFn: (id) => api.delete(`/question-bank/questions/${id}`), onSuccess: () => client.invalidateQueries({ queryKey: ['question-bank'] }), onSettled: () => setRemoving(null) })
  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-2">
        <h2 className="font-bold">{lesson.title}</h2>
        <button type="button" className="inline-flex items-center gap-2 rounded bg-brand px-4 py-1.5 text-white" onClick={() => setDialog({})}><Plus size={16} aria-hidden />{t('questionBank.newQuestion')}</button>
      </div>
      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : data.filter((q) => q.is_active).length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('questionBank.noQuestions')}</p> : (
        <ul className="space-y-2">
          {data.filter((q) => q.is_active).map((q) => (
            <li key={q.id} className="rounded-lg border bg-white p-3 text-sm">
              <div className="flex items-start justify-between gap-2">
                <p className="font-medium"><RichText text={q.body} /></p>
                <span className="flex shrink-0 items-center gap-3">
                  {q.author && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{q.author}</span>}
                  {q.mine && <button type="button" aria-label={t('admin.actions.edit')} className="text-brand" onClick={() => setDialog({ question: q })}><Pencil size={16} aria-hidden /></button>}
                  {q.mine && <button type="button" aria-label={t('admin.actions.delete')} className="text-red-700" onClick={() => setRemoving(q)}><Trash2 size={16} aria-hidden /></button>}
                </span>
              </div>
              {q.has_image && <div className="mt-2"><QuestionImage path={`/question-bank/questions/${q.id}/image/question`} alt={t('questionBank.imageAlt')} /></div>}
              <ul className="mt-2 space-y-1">{q.options.map((o) => <li key={o.id} className={o.is_correct ? 'font-bold text-green-700' : 'text-slate-600'}>{o.is_correct ? '✓ ' : '• '}<RichText text={o.body} /></li>)}</ul>
            </li>
          ))}
        </ul>
      )}
      {dialog && <QuestionDialog lessonId={lesson.id} question={dialog.question} onClose={() => setDialog(null)} />}
      <ConfirmDialog open={removing !== null} message={t('questionBank.confirmDeleteQuestion')} confirmLabel={t('admin.actions.delete')} onConfirm={() => del.mutate(removing.id)} onCancel={() => setRemoving(null)} />
    </div>
  )
}

// Teachers (and the admin) write lessons and multiple-choice questions, grade by grade and subject by subject.
export default function QuestionBankPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const lang = i18n.language
  const [pairKey, setPairKey] = useState('')
  const [lessonId, setLessonId] = useState(null)
  const [title, setTitle] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [renaming, setRenaming] = useState(null)
  const [removing, setRemoving] = useState(null)
  const scope = useQuery({ queryKey: ['question-bank', 'scope'], queryFn: async () => (await api.get('/question-bank/scope')).data.data })
  const pairs = scope.data ?? []
  const pair = pairs.find((p) => `${p.grade_level_id}-${p.subject_id}` === pairKey) ?? pairs[0]
  const lessons = useQuery({
    queryKey: ['question-bank', 'lessons', pair?.grade_level_id, pair?.subject_id], enabled: Boolean(pair),
    queryFn: async () => (await api.get('/question-bank/lessons', { params: { grade_level_id: pair.grade_level_id, subject_id: pair.subject_id } })).data.data,
  })
  const refresh = () => client.invalidateQueries({ queryKey: ['question-bank'] })
  const add = useMutation({
    mutationFn: () => api.post('/question-bank/lessons', { grade_level_id: pair.grade_level_id, subject_id: pair.subject_id, title }),
    onSuccess: (res) => { setTitle(''); setError(''); setNotice(res.data.existing ? t('questionBank.lessonReused', { title: res.data.data.title }) : ''); setLessonId(res.data.data.id); refresh() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const rename = useMutation({ mutationFn: () => api.put(`/question-bank/lessons/${renaming.id}`, { title: renaming.title }), onSuccess: () => { setRenaming(null); refresh() }, onError: (err) => setError(errorOf(err, t('admin.actions.error'))) })
  const del = useMutation({ mutationFn: (id) => api.delete(`/question-bank/lessons/${id}`), onSuccess: () => { setLessonId(null); setError(''); refresh() }, onError: (err) => setError(errorOf(err, t('admin.actions.error'))), onSettled: () => setRemoving(null) })
  const lesson = (lessons.data ?? []).find((l) => l.id === lessonId)
  if (scope.data && pairs.length === 0) return <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('questionBank.nothingToTeach')}</p>
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('questionBank.title')}</h1>
      {pair && (
        <label className="block text-sm sm:max-w-md">{t('questionBank.gradeAndSubject')}
          <select className="mt-1 w-full rounded border p-2" value={`${pair.grade_level_id}-${pair.subject_id}`} onChange={(e) => { setPairKey(e.target.value); setLessonId(null) }}>
            {pairs.map((p) => <option key={`${p.grade_level_id}-${p.subject_id}`} value={`${p.grade_level_id}-${p.subject_id}`}>{p[`grade_${lang}`] || p.grade_ar} · {p[`subject_${lang}`] || p.subject_ar}</option>)}
          </select>
        </label>
      )}
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      {notice && <p role="status" className="rounded bg-sky-tint p-2 text-sm">{notice}</p>}
      <p className="text-sm text-slate-600">{t('questionBank.sharedHint')}</p>
      <div className="grid gap-4 lg:grid-cols-[18rem_1fr]">
        <aside className="space-y-3">
          <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
            <input required dir="auto" aria-label={t('questionBank.lessonTitle')} placeholder={t('questionBank.lessonTitle')} className="min-w-0 flex-1 rounded border p-2" value={title} onChange={(e) => setTitle(e.target.value)} />
            <button type="submit" disabled={add.isPending} className="rounded bg-brand px-3 py-2 text-white">{t('admin.actions.add')}</button>
          </form>
          <ul className="divide-y rounded-lg border bg-white text-sm">
            {(lessons.data ?? []).length === 0 && <li className="p-3 text-slate-500">{t('questionBank.noLessons')}</li>}
            {(lessons.data ?? []).map((l) => (
              <li key={l.id} className={`flex items-center justify-between gap-2 p-2 ${l.id === lessonId ? 'bg-sky-tint' : ''}`}>
                {renaming?.id === l.id ? (
                  <form className="flex flex-1 gap-1" onSubmit={(e) => { e.preventDefault(); rename.mutate() }}>
                    <input required dir="auto" aria-label={t('questionBank.lessonTitle')} className="min-w-0 flex-1 rounded border p-1" value={renaming.title} onChange={(e) => setRenaming({ ...renaming, title: e.target.value })} />
                    <button type="submit" className="text-brand">{t('admin.actions.save')}</button>
                  </form>
                ) : (
                  <>
                    <button type="button" dir="auto" className="min-w-0 flex-1 text-start" onClick={() => { setNotice(''); setLessonId(l.id) }}>{l.title} <span className="text-xs text-slate-500">({l.questions})</span>{l.teachers?.length > 0 && <span className="block text-xs text-slate-500">{l.teachers.join('، ')}</span>}</button>
                    {l.mine && <button type="button" aria-label={t('admin.actions.edit')} className="text-brand" onClick={() => setRenaming({ id: l.id, title: l.title })}><Pencil size={15} aria-hidden /></button>}
                    {l.mine && <button type="button" aria-label={t('admin.actions.delete')} className="text-red-700" onClick={() => setRemoving(l)}><Trash2 size={15} aria-hidden /></button>}
                  </>
                )}
              </li>
            ))}
          </ul>
        </aside>
        <div>{lesson ? <Questions lesson={lesson} /> : <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('questionBank.pickLesson')}</p>}</div>
      </div>
      <ConfirmDialog open={removing !== null} message={t('questionBank.confirmDeleteLesson')} confirmLabel={t('admin.actions.delete')} onConfirm={() => del.mutate(removing.id)} onCancel={() => setRemoving(null)} />
    </section>
  )
}
