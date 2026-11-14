import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { ArrowUpRight } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import ExportButton from '../../../components/shared/ExportButton'
import { api } from '../../../api/client'
import Money from '../components/Money'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// Every student's balance this year, with filters; click a student for the statement and to record payments.
export default function AccountsPage({ base }) {
  const { t, i18n } = useTranslation()
  const [filters, setFilters] = useState({ grade_level_id: '', classroom_id: '', status: '', search: '' })
  const [page, setPage] = useState(1)
  const set = (key) => (e) => { setFilters({ ...filters, [key]: e.target.value }); setPage(1) }
  const params = { ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '')), page }

  const options = useQuery({ queryKey: ['finance', 'options'], queryFn: async () => (await api.get('/finance/options')).data.data })
  const list = useQuery({ queryKey: ['finance', 'accounts', params], queryFn: async () => (await api.get('/finance/accounts', { params })).data, placeholderData: (prev) => prev })
  const grade = (g) => g[`name_${i18n.language}`] || g.name_ar
  const rooms = (options.data?.classrooms ?? []).filter((c) => !filters.grade_level_id || String(c.grade_level_id) === filters.grade_level_id)

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('finance.accounts')}</h1>
        <ExportButton path="/finance/accounts/export" params={Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))} fallbackName="accounts.xlsx" />
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <label className="text-sm">{t('finance.fields.grade')}
          <select className="mt-1 block rounded border p-2" value={filters.grade_level_id} onChange={(e) => { setFilters({ ...filters, grade_level_id: e.target.value, classroom_id: '' }); setPage(1) }}>
            <option value="">{t('finance.all')}</option>{(options.data?.grades ?? []).map((g) => <option key={g.id} value={g.id}>{grade(g)}</option>)}
          </select>
        </label>
        <label className="text-sm">{t('academic.fields.classroom')}
          <select className="mt-1 block rounded border p-2" value={filters.classroom_id} onChange={set('classroom_id')}>
            <option value="">{t('finance.all')}</option>{rooms.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </label>
        <label className="text-sm">{t('finance.fields.status')}
          <select className="mt-1 block rounded border p-2" value={filters.status} onChange={set('status')}>
            <option value="">{t('finance.all')}</option>{['overdue', 'owing', 'settled'].map((s) => <option key={s} value={s}>{t(`finance.filter.${s}`)}</option>)}
          </select>
        </label>
        <label className="text-sm">{t('admin.actions.search')}<input className="mt-1 block rounded border p-2" value={filters.search} onChange={set('search')} /></label>
      </div>

      <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-100"><tr>{['student', 'classroom', 'net', 'paid', 'remaining', 'overdue'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`finance.cols.${k}`)}</th>)}</tr></thead>
          <tbody>
            {(list.data?.data ?? []).map((r) => (
              <tr key={r.id} className="border-t">
                <td className="p-2"><Link className="inline-flex items-center gap-1 text-brand" to={`${base}/finance/students/${r.id}`}>{r[`name_${i18n.language}`] || r.name_ar}<ArrowUpRight size={14} aria-hidden /></Link></td>
                <td className="p-2">{r.classroom}</td>
                <td className="p-2"><Money value={r.net} currency={list.data.currency} /></td>
                <td className="p-2"><Money value={r.paid} currency={list.data.currency} /></td>
                <td className="p-2"><Money value={r.remaining} currency={list.data.currency} /></td>
                <td className={`p-2 ${Number(r.overdue) > 0 ? 'font-bold text-red-700' : ''}`}><Money value={r.overdue} currency={list.data.currency} /></td>
              </tr>
            ))}
          </tbody>
        </table>
        {list.isSuccess && list.data.data.length === 0 && <p className="p-4 text-slate-600">{t('finance.noAccounts')}</p>}
      </div>
      {list.data && list.data.last_page > 1 && (
        <div className="flex items-center justify-center gap-3 text-sm">
          <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1 disabled:opacity-50" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('finance.prev')}</InteractiveHoverButton>
          <span>{page} / {list.data.last_page}</span>
          <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1 disabled:opacity-50" disabled={page >= list.data.last_page} onClick={() => setPage(page + 1)}>{t('finance.next')}</InteractiveHoverButton>
        </div>
      )}
    </section>
  )
}
