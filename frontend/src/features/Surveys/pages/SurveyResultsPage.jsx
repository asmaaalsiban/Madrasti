import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AttachmentList } from '../../../components/shared/Attachments'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const STATE_STYLE = { pending: 'bg-amber-100 text-amber-800', answered: 'bg-green-100 text-green-800', approved: 'bg-green-100 text-green-800', declined: 'bg-red-100 text-red-700' }

// The results of one survey: counts per choice, written answers, and who has (not) answered, with close / remind / delete.
export default function SurveyResultsPage({ base }) {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const navigate = useNavigate()
  const client = useQueryClient()
  const [notice, setNotice] = useState('')
  const [confirmDelete, setConfirmDelete] = useState(false)
  const { data, isLoading, isError } = useQuery({ queryKey: ['surveys', id], queryFn: async () => (await api.get(`/surveys/${id}`)).data.data })
  const refresh = () => client.invalidateQueries({ queryKey: ['surveys'] })
  const fail = (err) => setNotice(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error'))
  const close = useMutation({ mutationFn: () => api.post(`/surveys/${id}/close`), onSuccess: () => { setNotice(''); refresh() }, onError: fail })
  const remind = useMutation({ mutationFn: async () => (await api.post(`/surveys/${id}/remind`)).data.data, onSuccess: (r) => setNotice(t('surveys.reminded', { count: r.reminded })), onError: fail })
  const remove = useMutation({ mutationFn: () => api.delete(`/surveys/${id}`), onSuccess: () => { refresh(); navigate(`${base}/surveys`) }, onError: (e) => { setConfirmDelete(false); fail(e) } })

  if (isLoading) return <p className="text-slate-500">{t('common.loading')}</p>
  if (isError || !data) return <p role="alert" className="text-red-700">{t('admin.actions.error')}</p>
  const nameOf = (s) => (s ? s[`name_${i18n.language}`] || s.name_ar : '')
  const grade = (g) => (g ? g[`name_${i18n.language}`] || g.name_ar : '')
  const audienceText = data.audience === 'grades' ? t('surveys.gradeRange', { from: grade(data.audience_detail.from), to: grade(data.audience_detail.to) }) : data.audience === 'classrooms' ? (data.audience_detail ?? []).join(' · ') : data.audience === 'staff' ? t('surveys.staffCount', { count: data.recipient_count }) : t('surveys.allParents')
  const open = data.status === 'open'
  const pending = data.recipients.filter((r) => r.state === 'pending').length

  return (
    <section className="space-y-4">
      <div className="space-y-1">
        <h1 className="text-xl font-bold">{data.title}</h1>
        <p className="text-sm text-slate-600">{t(`surveys.types.${data.type}`)} · {t(`surveys.status.${data.status}`)} · {t('surveys.answered', { done: data.response_count, total: data.recipient_count })}</p>
        <p className="text-sm text-slate-600">{t('surveys.sentTo')}: {audienceText} · {t('surveys.answeredBy')}: {t(`surveys.who.${data.respondents}`)}</p>
        {data.description && <p className="rounded bg-white p-3 text-sm">{data.description}</p>}
        <AttachmentList items={data.attachments} />
      </div>

      <div className="flex flex-wrap gap-2">
        <Link to={`${base}/surveys/${id}/responses`} className="rounded bg-brand px-3 py-1.5 text-sm text-white">{t('surveys.openTable')}</Link>
        {open && pending > 0 && <InteractiveHoverButton variant="outline" type="button" disabled={remind.isPending} className="px-3 py-1.5 text-sm text-brand disabled:opacity-50" onClick={() => remind.mutate()}>{t('surveys.remind', { count: pending })}</InteractiveHoverButton>}
        {open && <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5 text-sm" onClick={() => close.mutate()}>{t('surveys.close')}</InteractiveHoverButton>}
        <button type="button" className="rounded border border-red-300 px-3 py-1.5 text-sm text-red-700" onClick={() => setConfirmDelete(true)}>{t('admin.actions.delete')}</button>
      </div>
      {notice && <p role="status" className="rounded bg-amber-50 p-2 text-sm text-amber-900">{notice}</p>}

      {data.type === 'approval' && (
        <div className="grid grid-cols-3 gap-3 text-center">
          {[['approved', data.approved], ['declined', data.declined], ['pending', pending]].map(([k, n]) => (
            <div key={k} className="rounded-lg border bg-white p-3"><p className="text-2xl font-bold">{n}</p><p className="text-sm text-slate-600">{t(`surveys.state.${k}`)}</p></div>
          ))}
        </div>
      )}

      {data.questions.map((q) => (
        <div key={q.id} className="space-y-2 rounded-lg border bg-white p-4">
          <h2 className="font-bold">{q.text}</h2>
          {q.type === 'text' ? (
            q.texts.length === 0 ? <p className="text-sm text-slate-500">{t('surveys.noAnswers')}</p> : <ul className="list-disc space-y-1 ps-5 text-sm">{q.texts.map((x, i) => <li key={i}>{x}</li>)}</ul>
          ) : (
            <ul className="space-y-1.5">
              {q.counts.map((c) => {
                const share = q.answered > 0 ? Math.round((c.count / q.answered) * 100) : 0
                return (
                  <li key={c.option} className="text-sm">
                    <div className="flex justify-between"><span>{q.type === 'yes_no' ? t(`surveys.${c.option}`) : c.option}</span><span dir="ltr">{c.count} ({share}%)</span></div>
                    <div className="h-2 rounded bg-slate-100"><div className="h-2 rounded bg-brand" style={{ width: `${share}%` }} /></div>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      ))}

      <div className="space-y-2 rounded-lg border bg-white p-4">
        <h2 className="font-bold">{t('surveys.parents')}</h2>
        <ul className="divide-y text-sm">
          {data.recipients.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
              <span>{r.name}<span className="ms-1 rounded bg-slate-100 px-1.5 text-xs text-slate-600">{t(`surveys.role.${r.role}`)}</span>{r.role === 'staff' && r.position && <span className="text-slate-500"> · {t(`staff.types.${r.position}`)}</span>}{r.role === 'parent' && r.student && <span className="text-slate-500"> · {nameOf(r.student)}</span>}{r.role === 'student' && r.classroom && <span className="text-slate-500"> · {r.classroom}</span>}{r.phone && <span dir="ltr" className="ms-2 text-xs text-slate-400">{r.phone}</span>}</span>
              <span className={`rounded-full px-2 py-0.5 text-xs ${STATE_STYLE[r.state]}`}>{t(`surveys.state.${r.state}`)}</span>
            </li>
          ))}
        </ul>
      </div>
      <ConfirmDialog open={confirmDelete} message={t('surveys.confirmDelete')} onConfirm={() => remove.mutate()} onCancel={() => setConfirmDelete(false)} />
    </section>
  )
}
