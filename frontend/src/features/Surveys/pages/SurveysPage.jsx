import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import SurveyForm from '../components/SurveyForm'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// Admin / teacher: the polls, surveys and approvals they sent, with how many parents answered.
export default function SurveysPage({ base }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [creating, setCreating] = useState(false)
  const pending = useQuery({ queryKey: ['my-surveys', 'pending'], queryFn: async () => (await api.get('/my-surveys/pending')).data.data.count })
  const list = useQuery({ queryKey: ['surveys'], queryFn: async () => (await api.get('/surveys')).data.data })

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('surveys.title')}</h1>
        <div className="flex flex-wrap gap-2">
          <Link to={`${base}/my-surveys`} className="rounded border px-3 py-2 text-sm text-brand">{t('surveys.forMe')}{pending.data > 0 && <span className="ms-1 rounded-full bg-brand px-2 text-xs text-white">{pending.data}</span>}</Link>
          <InteractiveHoverButton type="button" className="px-4 py-2" onClick={() => setCreating(true)}>{t('surveys.new')}</InteractiveHoverButton>
        </div>
      </div>
      <p className="text-sm text-slate-600">{t('surveys.help')}</p>
      {list.isSuccess && list.data.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('surveys.none')}</p>}
      <ul className="space-y-2">
        {(list.data ?? []).map((s) => (
          <li key={s.id}>
            <Link to={`${base}/surveys/${s.id}`} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-white p-3 hover:border-brand">
              <span>
                <span className="font-bold">{s.title}</span>
                <span className="ms-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs">{t(`surveys.types.${s.type}`)}</span>
                {s.respondents !== 'parents' && <span className="ms-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-800">{t(`surveys.who.${s.respondents}`)}</span>}
                <span className={`ms-2 rounded-full px-2 py-0.5 text-xs ${s.status === 'open' ? 'bg-green-100 text-green-800' : 'bg-slate-200 text-slate-700'}`}>{t(`surveys.status.${s.status}`)}</span>
              </span>
              <span className="text-sm text-slate-600">{t('surveys.answered', { done: s.response_count, total: s.recipient_count })}</span>
            </Link>
          </li>
        ))}
      </ul>
      {creating && <SurveyForm isAdmin={base === '/admin'} onClose={() => setCreating(false)} onCreated={(s) => { setCreating(false); navigate(`${base}/surveys/${s.id}`) }} />}
    </section>
  )
}
