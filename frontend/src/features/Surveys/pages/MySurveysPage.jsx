import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AttachmentList } from '../../../components/shared/Attachments'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

const STATE_STYLE = { pending: 'bg-amber-100 text-amber-800', answered: 'bg-green-100 text-green-800', approved: 'bg-green-100 text-green-800', declined: 'bg-red-100 text-red-700' }

function Question({ q, value, onChange, disabled }) {
  const { t } = useTranslation()
  const choose = (option) => (q.type === 'multiple_choice' ? onChange((value ?? []).includes(option) ? value.filter((o) => o !== option) : [...(value ?? []), option]) : onChange(option))
  return (
    <fieldset className="space-y-1.5" disabled={disabled}>
      <legend className="text-sm font-medium">{q.text}{q.is_required && <span className="text-red-600"> *</span>}</legend>
      {q.type === 'text' && <textarea aria-label={q.text} rows={2} className="w-full rounded border p-2 text-sm" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />}
      {q.type === 'yes_no' && ['yes', 'no'].map((o) => (
        <label key={o} className="flex items-center gap-2 text-sm"><input type="radio" name={`q${q.id}`} checked={value === o || (typeof value === 'boolean' && value === (o === 'yes'))} onChange={() => onChange(o)} />{t(`surveys.${o}`)}</label>
      ))}
      {q.type === 'single_choice' && q.options.map((o) => (
        <label key={o} className="flex items-center gap-2 text-sm"><input type="radio" name={`q${q.id}`} checked={value === o} onChange={() => choose(o)} />{o}</label>
      ))}
      {q.type === 'multiple_choice' && q.options.map((o) => (
        <label key={o} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={(value ?? []).includes(o)} onChange={() => choose(o)} />{o}</label>
      ))}
    </fieldset>
  )
}

// One survey for one parent (and child, for an approval).
function SurveyCard({ s }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [answers, setAnswers] = useState(s.answers ?? {})
  const [editing, setEditing] = useState(s.state === 'pending')
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const child = s.student ? s.student[`name_${i18n.language}`] || s.student.name_ar : null

  const send = useMutation({
    mutationFn: (d) => api.post(`/my-surveys/${s.id}/respond`, { decision: d || null, answers }),
    onSuccess: () => { setErrors({}); setMessage(t('surveys.thanks')); setEditing(false); client.invalidateQueries({ queryKey: ['my-surveys'] }) },
    onError: (err) => { setErrors(err.response?.data?.errors ?? {}); setMessage(err.response?.data?.errors ? '' : t('admin.actions.error')) },
  })
  const submit = (d) => send.mutate(d)
  const canAnswer = s.open && editing

  return (
    <li className="space-y-3 rounded-lg border bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="font-bold">{s.title}</p>
          <p className="text-xs text-slate-500">{t(`surveys.types.${s.type}`)}{child ? ` · ${child}` : ''}{s.closes_at ? ` · ${t('surveys.until', { date: s.closes_at.slice(0, 10) })}` : ''}</p>
        </div>
        <span className={`rounded-full px-2 py-0.5 text-xs ${STATE_STYLE[s.state]}`}>{t(`surveys.state.${s.state}`)}{!s.open && s.state === 'pending' ? ` · ${t('surveys.status.closed')}` : ''}</span>
      </div>
      {s.description && <p className="text-sm text-slate-700">{s.description}</p>}
      <AttachmentList items={s.attachments} />

      {(canAnswer || s.state !== 'pending') && s.questions.map((q) => (
        <div key={q.id}>
          <Question q={q} value={answers[q.id]} disabled={!canAnswer} onChange={(v) => setAnswers({ ...answers, [q.id]: v })} />
          {errors[`answers.${q.id}`] && <p role="alert" className="text-xs text-red-700">{errors[`answers.${q.id}`][0]}</p>}
        </div>
      ))}
      {errors.decision && <p role="alert" className="text-xs text-red-700">{errors.decision[0]}</p>}
      {message && <p role="status" className="text-sm text-green-700">{message}</p>}

      {canAnswer && s.type === 'approval' && (
        <div className="flex flex-wrap gap-2">
          <button type="button" disabled={send.isPending} className="rounded bg-green-700 px-4 py-2 text-white disabled:opacity-50" onClick={() => submit('approved')}>{t('surveys.approve')}</button>
          <button type="button" disabled={send.isPending} className="rounded border border-red-300 px-4 py-2 text-red-700 disabled:opacity-50" onClick={() => submit('declined')}>{t('surveys.decline')}</button>
        </div>
      )}
      {canAnswer && s.type !== 'approval' && <button type="button" disabled={send.isPending} className="rounded bg-brand px-4 py-2 text-white disabled:opacity-50" onClick={() => submit(null)}>{t('surveys.submit')}</button>}
      {s.open && !editing && <button type="button" className="text-sm text-brand underline" onClick={() => setEditing(true)}>{t('surveys.change')}</button>}
    </li>
  )
}

// A parent's surveys, polls and approvals: what is waiting first.
export default function MySurveysPage() {
  const { t } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['my-surveys'], queryFn: async () => (await api.get('/my-surveys')).data.data })
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('surveys.mine')}</h1>
      {isLoading ? <p>{t('common.loading')}</p> : data.length === 0 ? <p className="rounded bg-white p-4 text-slate-600">{t('surveys.noneForMe')}</p> : (
        <ul className="space-y-3">{data.map((s) => <SurveyCard key={s.id} s={s} />)}</ul>
      )}
    </section>
  )
}
