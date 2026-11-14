import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Money from '../../Finance/components/Money'
import { EndDialog, HistoryDialog, NewContractDialog, RenewDialog } from '../components/ContractDialogs'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const SOON = 60

// Staff contracts: who has one, when it ends, and renew (with the annual raise), end or write a new one.
// `canManage`: the accountant; the principal only reads.
export default function ContractsPage({ base, canManage }) {
  const { t, i18n } = useTranslation()
  const [filter, setFilter] = useState('all')
  const [search, setSearch] = useState('')
  const [dialog, setDialog] = useState(null) // { type, staff }
  const { data, isLoading } = useQuery({ queryKey: ['payroll', 'staff'], queryFn: async () => (await api.get('/payroll/staff')).data })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  const rows = data.data
  const currency = data.currency
  const stateOf = (r) => {
    const c = r.contract
    if (!c || c.status !== 'active') return 'none'
    if (c.days_left !== null && c.days_left < 0) return 'expired'
    if (c.days_left !== null && c.days_left <= SOON) return 'soon'
    return 'ok'
  }
  const counts = { all: rows.length, soon: rows.filter((r) => ['soon', 'expired'].includes(stateOf(r))).length, none: rows.filter((r) => stateOf(r) === 'none').length }
  const q = search.trim().toLowerCase()
  const shown = rows.filter((r) => (filter === 'all' || (filter === 'soon' ? ['soon', 'expired'].includes(stateOf(r)) : stateOf(r) === filter)) && (!q || r.name.toLowerCase().includes(q)))
  const subject = (r) => (r.subject ? r.subject[`name_${i18n.language}`] || r.subject.name_ar : '')

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('payroll.contractsTitle')}</h1>
        <Link to={`${base}/payroll`} className="rounded border px-3 py-1.5 text-sm text-brand">{t('payroll.title')}</Link>
      </div>
      <p className="text-sm text-slate-600">{t('payroll.contractsHelp')}</p>
      <div className="flex flex-wrap items-center gap-2" role="tablist">
        {[['all', 'filterAll'], ['soon', 'filterSoon'], ['none', 'filterNone']].map(([k, label]) => (
          <button key={k} type="button" role="tab" aria-selected={filter === k} className={`rounded-full border px-3 py-1 text-sm ${filter === k ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => setFilter(k)}>{t(`payroll.${label}`)} ({counts[k]})</button>
        ))}
        <input type="search" aria-label={t('admin.actions.search')} placeholder={t('admin.actions.search')} className="ms-auto rounded border p-1.5 text-sm" value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>

      <div className="overflow-x-auto rounded-lg border bg-white">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-50"><tr>{['name', 'position', 'salary', 'period', 'state'].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`payroll.cols.${c}`)}</th>)}<th><span className="sr-only">{t('a11y.actions')}</span></th></tr></thead>
          <tbody>
            {shown.map((r) => {
              const s = stateOf(r)
              const c = r.contract
              return (
                <tr key={r.id} className="border-t align-top">
                  <td className="p-2 font-medium">{r.name}</td>
                  <td className="p-2 text-slate-600">{t(`staff.types.${r.staff_type}`)}{subject(r) ? ` · ${subject(r)}` : ''}</td>
                  <td className="p-2">{c ? <Money value={c.base_salary} currency={currency} /> : '—'}</td>
                  <td dir="ltr" className="p-2 text-start text-slate-600">{c ? `${c.starts_on} → ${c.ends_on ?? '—'}` : '—'}</td>
                  <td className="p-2">
                    <span className={`rounded-full px-2 py-0.5 text-xs ${{ ok: 'bg-green-100 text-green-800', soon: 'bg-amber-100 text-amber-800', expired: 'bg-red-100 text-red-700', none: 'bg-slate-100 text-slate-600' }[s]}`}>
                      {s === 'soon' ? t('payroll.state.soon', { days: c.days_left }) : t(`payroll.state.${s}`)}
                    </span>
                  </td>
                  <td className="p-2">
                    <span className="flex flex-wrap justify-end gap-2">
                      {canManage && c?.status === 'active' && <InteractiveHoverButton type="button" className="px-3 py-1" onClick={() => setDialog({ type: 'renew', staff: r })}>{t('payroll.renew')}</InteractiveHoverButton>}
                      {canManage && c?.status === 'active' && <button type="button" className="rounded border border-red-300 px-3 py-1 text-red-700" onClick={() => setDialog({ type: 'end', staff: r })}>{t('payroll.endContract')}</button>}
                      {canManage && (!c || c.status !== 'active') && <InteractiveHoverButton type="button" className="px-3 py-1" onClick={() => setDialog({ type: 'new', staff: r })}>{t('payroll.newContract')}</InteractiveHoverButton>}
                      {r.contracts_count > 0 && <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1 text-brand" onClick={() => setDialog({ type: 'history', staff: r })}>{t('payroll.history')}</InteractiveHoverButton>}
                    </span>
                  </td>
                </tr>
              )
            })}
            {shown.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-slate-500">{t('payroll.noRows')}</td></tr>}
          </tbody>
        </table>
      </div>

      {dialog?.type === 'new' && <NewContractDialog staff={dialog.staff} onClose={() => setDialog(null)} />}
      {dialog?.type === 'renew' && <RenewDialog staff={dialog.staff} currency={currency} onClose={() => setDialog(null)} />}
      {dialog?.type === 'end' && <EndDialog staff={dialog.staff} onClose={() => setDialog(null)} />}
      {dialog?.type === 'history' && <HistoryDialog staff={dialog.staff} currency={currency} onClose={() => setDialog(null)} />}
    </section>
  )
}
