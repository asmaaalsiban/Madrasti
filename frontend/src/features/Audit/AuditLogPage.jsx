import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { DateInput } from '../../components/shared/DateInput'
import ExportButton from '../../components/shared/ExportButton'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

const ACTION_STYLE = { created: 'bg-green-100 text-green-800', updated: 'bg-amber-100 text-amber-800', deleted: 'bg-red-100 text-red-700' }
const show = (v) => (v === null || v === undefined ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v))

// The audit log: who created, changed or deleted what, and when. Read only.
export default function AuditLogPage() {
  const { t, i18n } = useTranslation()
  const [filters, setFilters] = useState({ action: '', type: '', user_id: '', from: '', to: '', record_id: '' })
  const [page, setPage] = useState(1)
  const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
  const options = useQuery({ queryKey: ['audit', 'filters'], queryFn: async () => (await api.get('/audit-logs/filters')).data })
  const { data, isLoading } = useQuery({ queryKey: ['audit', params, page], queryFn: async () => (await api.get('/audit-logs', { params: { ...params, page } })).data })
  const set = (k) => (e) => { setPage(1); setFilters({ ...filters, [k]: e.target.value }) }
  const typeName = (type) => t(`audit.types.${type}`, { defaultValue: type })
  const when = (iso) => new Date(iso).toLocaleString(i18n.language === 'ar' ? 'ar-JO-u-nu-latn' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('audit.title')}</h1>
        <ExportButton path="/audit-logs/export" params={params} fallbackName="audit-log.xlsx" />
      </div>
      <p className="text-sm text-slate-600">{t('audit.help')}</p>

      <div className="grid gap-3 rounded-lg border bg-white p-3 sm:grid-cols-2 lg:grid-cols-3">
        <label className="block text-sm">{t('audit.action')}
          <select className="mt-1 w-full rounded border p-2" value={filters.action} onChange={set('action')}>
            <option value="">{t('audit.all')}</option>
            {['created', 'updated', 'deleted'].map((a) => <option key={a} value={a}>{t(`audit.actions.${a}`)}</option>)}
          </select>
        </label>
        <label className="block text-sm">{t('audit.record')}
          <select className="mt-1 w-full rounded border p-2" value={filters.type} onChange={set('type')}>
            <option value="">{t('audit.all')}</option>
            {(options.data?.types ?? []).map((x) => <option key={x} value={x}>{typeName(x)}</option>)}
          </select>
        </label>
        <label className="block text-sm">{t('audit.who')}
          <select className="mt-1 w-full rounded border p-2" value={filters.user_id} onChange={set('user_id')}>
            <option value="">{t('audit.all')}</option>
            {(options.data?.users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
          </select>
        </label>
        <label className="block text-sm">{t('audit.from')}<DateInput className="mt-1 w-full rounded border p-2" value={filters.from} onChange={set('from')} /></label>
        <label className="block text-sm">{t('audit.to')}<DateInput className="mt-1 w-full rounded border p-2" value={filters.to} onChange={set('to')} /></label>
        <label className="block text-sm">{t('audit.recordId')}<input type="number" min="1" dir="ltr" className="mt-1 w-full rounded border p-2" value={filters.record_id} onChange={set('record_id')} /></label>
      </div>

      {isLoading || !data ? <p className="text-slate-500">{t('common.loading')}</p> : (
        <>
          <div className="overflow-x-auto rounded-lg border bg-white">
            <table className="w-full text-start text-sm">
              <thead className="bg-slate-50"><tr>{['when', 'who', 'action', 'record', 'changes'].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`audit.${c}`)}</th>)}</tr></thead>
              <tbody>
                {data.data.map((l) => (
                  <tr key={l.id} className="border-t align-top">
                    <td className="whitespace-nowrap p-2 text-slate-600">{when(l.at)}</td>
                    <td className="p-2">{l.user?.name ?? '—'}{l.user && <span className="block text-xs text-slate-500">{t(`roles.${l.user.role}`)}</span>}</td>
                    <td className="p-2"><span className={`rounded-full px-2 py-0.5 text-xs ${ACTION_STYLE[l.action]}`}>{t(`audit.actions.${l.action}`)}</span></td>
                    <td className="p-2">{typeName(l.type)} <span className="text-slate-500" dir="ltr">#{l.record_id}</span></td>
                    <td className="p-2">
                      <ul className="space-y-0.5 text-xs">
                        {l.changes.map((c) => (
                          <li key={c.field}><b>{t(`audit.fields.${c.field}`, { defaultValue: c.field })}</b>: {l.action === 'updated' && <><span className="text-red-700 line-through">{show(c.old)}</span> → </>}<span className={l.action === 'deleted' ? 'text-red-700' : 'text-green-800'}>{show(l.action === 'deleted' ? c.old : c.new)}</span></li>
                        ))}
                      </ul>
                    </td>
                  </tr>
                ))}
                {data.data.length === 0 && <tr><td colSpan={5} className="p-4 text-center text-slate-500">{t('audit.none')}</td></tr>}
              </tbody>
            </table>
          </div>
          <div className="flex items-center justify-between text-sm">
            <span className="text-slate-600">{t('audit.total', { count: data.meta.total })}</span>
            <span className="flex items-center gap-2">
              <InteractiveHoverButton variant="outline" type="button" disabled={page <= 1} className="px-3 py-1 disabled:opacity-40" onClick={() => setPage(page - 1)}>{t('audit.previous')}</InteractiveHoverButton>
              <span dir="ltr">{data.meta.current_page} / {data.meta.last_page}</span>
              <InteractiveHoverButton variant="outline" type="button" disabled={page >= data.meta.last_page} className="px-3 py-1 disabled:opacity-40" onClick={() => setPage(page + 1)}>{t('audit.next')}</InteractiveHoverButton>
            </span>
          </div>
        </>
      )}
    </section>
  )
}
