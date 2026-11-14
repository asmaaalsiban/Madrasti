import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Money from '../components/Money'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const BAR = { ok: 'bg-green-600', near: 'bg-amber-500', over: 'bg-red-600', unplanned: 'bg-red-600', none: 'bg-slate-300' }
const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// The yearly budget per kind of expense, how much is spent and what is left.
export default function BudgetsPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [yearId, setYearId] = useState('')
  const [edits, setEdits] = useState({})
  const [error, setError] = useState('')
  const { data } = useQuery({ queryKey: ['finance', 'budgets', yearId], queryFn: async () => (await api.get('/finance/budgets', { params: { academic_year_id: yearId || undefined } })).data })
  const year = data?.data.year
  const save = useMutation({
    mutationFn: () => api.put('/finance/budgets', { academic_year_id: year.id, items: Object.entries(edits).map(([category_id, amount]) => ({ category_id: Number(category_id), amount: amount === '' ? 0 : amount })) }),
    onSuccess: () => { setEdits({}); setError(''); client.invalidateQueries({ queryKey: ['finance', 'budgets'] }) },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  if (!data) return <p className="text-slate-500">{t('common.loading')}</p>
  const canManage = data.can_manage
  const c = data.currency
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('budgets.title')}</h1>
        {data.years.length > 0 && (
          <label className="text-sm">{t('budgets.year')}
            <select className="ms-2 rounded border p-1.5" value={yearId || year?.id || ''} onChange={(e) => { setYearId(e.target.value); setEdits({}) }}>
              {data.years.map((y) => <option key={y.id} value={y.id}>{y.name}</option>)}
            </select>
          </label>
        )}
      </div>
      {!year ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('budgets.noYear')}</p> : (
        <>
          <dl className="grid grid-cols-3 gap-3">
            {['budget', 'spent', 'remaining'].map((k) => <div key={k} className={`rounded-lg border bg-white p-4 ${k === 'remaining' && Number(data.data.totals.remaining) < 0 ? 'border-red-300' : ''}`}><dt className="text-sm text-slate-500">{t(`budgets.${k}`)}</dt><dd className="text-lg font-bold"><Money value={data.data.totals[k]} currency={c} /></dd></div>)}
          </dl>
          {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
          <ul className="space-y-3">
            {data.data.rows.map((r) => (
              <li key={r.category_id} className="rounded-lg border bg-white p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <b>{r[`name_${i18n.language}`] || r.name_ar}</b>
                  <span className={`text-xs ${r.status === 'over' || r.status === 'unplanned' ? 'font-bold text-red-700' : r.status === 'near' ? 'text-amber-700' : 'text-slate-500'}`}>{t(`budgets.status.${r.status}`)}</span>
                </div>
                <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100" aria-hidden><div className={`h-full ${BAR[r.status]}`} style={{ width: `${Math.min(100, r.percent ?? (r.status === 'unplanned' ? 100 : 0))}%` }} /></div>
                <div className="mt-2 grid items-center gap-2 text-sm sm:grid-cols-3">
                  <label>{t('budgets.budget')}
                    <input type="number" min="0" step="0.01" dir="ltr" disabled={!canManage} aria-label={`${t('budgets.budget')} ${r.name_en}`} className="mt-1 w-full rounded border p-1.5 disabled:bg-slate-50" value={edits[r.category_id] ?? r.budget} onChange={(e) => setEdits({ ...edits, [r.category_id]: e.target.value })} />
                  </label>
                  <span>{t('budgets.spent')}: <b><Money value={r.spent} currency={c} /></b></span>
                  <span>{t('budgets.remaining')}: <b><Money value={r.remaining} currency={c} /></b></span>
                </div>
              </li>
            ))}
          </ul>
          {canManage && <div className="flex justify-end"><InteractiveHoverButton type="button" disabled={save.isPending || Object.keys(edits).length === 0} className="px-5 py-1.5 disabled:opacity-50" onClick={() => save.mutate()}>{t('admin.actions.save')}</InteractiveHoverButton></div>}
        </>
      )}
    </section>
  )
}
