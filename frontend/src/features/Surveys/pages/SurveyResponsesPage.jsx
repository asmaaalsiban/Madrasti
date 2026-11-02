import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ExportButton from '../../../components/shared/ExportButton'

const STYLE = { pending: 'bg-amber-100 text-amber-800', answered: 'bg-green-100 text-green-800', approved: 'bg-green-100 text-green-800', declined: 'bg-red-100 text-red-700' }
const plain = (t, v) => (v === undefined || v === null ? '' : typeof v === 'boolean' ? t(v ? 'surveys.yes' : 'surveys.no') : Array.isArray(v) ? v.join(', ') : String(v))

// The table of every parent: who approved, who declined, who has not answered, with the child, section, phone and extra answers.
export default function SurveyResponsesPage({ base }) {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const [filter, setFilter] = useState('all')
  const [search, setSearch] = useState('')
  const { data, isLoading, isError } = useQuery({ queryKey: ['surveys', id], queryFn: async () => (await api.get(`/surveys/${id}`)).data.data })
  if (isLoading) return <p className="text-slate-500">{t('common.loading')}</p>
  if (isError || !data) return <p role="alert" className="text-red-700">{t('admin.actions.error')}</p>

  const nameOf = (s) => (s ? s[`name_${i18n.language}`] || s.name_ar : '')
  const approval = data.type === 'approval'
  const withChild = data.recipients.some((r) => r.student)
  const states = approval ? ['approved', 'declined', 'pending'] : ['answered', 'pending']
  const count = (s) => data.recipients.filter((r) => r.state === s).length
  const q = search.trim().toLowerCase()
  const rows = data.recipients.filter((r) => (filter === 'all' || r.state === filter)
    && (!q || [r.name, r.phone, nameOf(r.student), r.classroom].filter(Boolean).join(' ').toLowerCase().includes(q)))

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-xl font-bold">{data.title}</h1>
          <p className="text-sm text-slate-600">{t('surveys.responsesTable')} · {t('surveys.answered', { done: data.response_count, total: data.recipient_count })}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to={`${base}/surveys/${id}`} className="rounded border px-3 py-1.5 text-sm text-brand">{t('surveys.backToResults')}</Link>
          <ExportButton path={`/surveys/${id}/export`} params={filter === 'all' ? {} : { state: filter }} fallbackName="survey.xlsx" />
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2" role="tablist">
        {['all', ...states].map((s) => (
          <button key={s} type="button" role="tab" aria-selected={filter === s} className={`rounded-full border px-3 py-1 text-sm ${filter === s ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => setFilter(s)}>
            {t(`surveys.filter.${s}`)} ({s === 'all' ? data.recipients.length : count(s)})
          </button>
        ))}
        <input type="search" aria-label={t('admin.actions.search')} placeholder={t('admin.actions.search')} className="ms-auto rounded border p-1.5 text-sm" value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>

      <div className="overflow-x-auto rounded-lg border bg-white">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-50">
            <tr>
              {['name', 'role', ...(withChild ? ['student', 'classroom'] : []), 'phone', 'state', 'answeredAt'].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`surveys.cols.${c}`)}</th>)}
              {data.questions.map((qn) => <th key={qn.id} className="p-2 text-start font-medium">{qn.text}</th>)}
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-t">
                <td className="p-2 font-medium">{r.name}</td>
                <td className="p-2">{t(`surveys.role.${r.role}`)}</td>
                {withChild && <td className="p-2">{nameOf(r.student)}</td>}
                {withChild && <td className="p-2">{r.classroom ?? '—'}</td>}
                <td dir="ltr" className="p-2 text-start">{r.phone ?? '—'}</td>
                <td className="p-2"><span className={`rounded-full px-2 py-0.5 text-xs ${STYLE[r.state]}`}>{t(`surveys.state.${r.state}`)}</span></td>
                <td dir="ltr" className="p-2 text-start text-slate-600">{r.answered_at ? r.answered_at.slice(0, 16).replace('T', ' ') : '—'}</td>
                {data.questions.map((qn) => <td key={qn.id} className="p-2">{plain(t, r.answers?.[qn.id])}</td>)}
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={8} className="p-4 text-center text-slate-500">{t('surveys.noRows')}</td></tr>}
          </tbody>
        </table>
      </div>
    </section>
  )
}
