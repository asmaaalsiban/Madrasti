import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { STATES, STATE_STYLE, formatDay } from './format'
import { useSchools } from './useSuperAdmin'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

export const StateBadge = ({ state }) => {
  const { t } = useTranslation()
  return <span className={`rounded-full px-2 py-0.5 text-xs ${STATE_STYLE[state] ?? ''}`}>{t(`superAdmin.states.${state}`)}</span>
}

// The schools of the platform: state, end date, students used / limit. Search by name or code, filter by state.
export default function SchoolsPage() {
  const { t, i18n } = useTranslation()
  const [search, setSearch] = useState('')
  const [term, setTerm] = useState('')
  const [state, setState] = useState('')
  const [page, setPage] = useState(1)
  // Wait for a pause in typing before asking the server.
  useEffect(() => {
    const id = setTimeout(() => { setTerm(search.trim()); setPage(1) }, 300)
    return () => clearTimeout(id)
  }, [search])

  const params = { ...(term && { search: term }), ...(state && { state }), page }
  const { data, isLoading, isError } = useSchools(params)
  const rows = data?.data ?? []

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('superAdmin.schools')}</h1>
        <Link to="/super-admin/schools/new" className="rounded bg-brand px-4 py-2 font-semibold text-white">{t('superAdmin.addSchool')}</Link>
      </div>

      <div className="grid gap-3 rounded-lg border bg-white p-3 sm:grid-cols-2">
        <label className="block text-sm">{t('admin.actions.search')}
          <input type="search" className="mt-1 w-full rounded border p-2" placeholder={t('superAdmin.searchHint')} value={search} onChange={(e) => setSearch(e.target.value)} />
        </label>
        <label className="block text-sm">{t('superAdmin.status')}
          <select className="mt-1 w-full rounded border p-2" value={state} onChange={(e) => { setState(e.target.value); setPage(1) }}>
            <option value="">{t('superAdmin.allStates')}</option>
            {STATES.map((s) => <option key={s} value={s}>{t(`superAdmin.states.${s}`)}</option>)}
          </select>
        </label>
      </div>

      {isLoading && <p className="text-slate-500">{t('common.loading')}</p>}
      {isError && <p role="alert" className="text-red-700">{t('superAdmin.genericError')}</p>}
      {data && (
        <>
          <div className="rounded-lg border bg-white">
            <table className="w-full text-start text-sm">
              <thead className="bg-slate-50">
                <tr>
                  <th className="p-2 text-start font-medium">{t('superAdmin.name')}</th>
                  <th className="p-2 text-start font-medium">{t('superAdmin.status')}</th>
                  <th className="p-2 text-start font-medium">{t('superAdmin.endsAt')}</th>
                  <th className="p-2 text-start font-medium">{t('superAdmin.students')}</th>
                  <th className="p-2 text-start font-medium">{t('admin.actions.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((s) => (
                  <tr key={s.id} className="border-t">
                    <td className="p-2 font-semibold">{s.name} <span className="block text-xs font-normal text-slate-500" dir="ltr">{s.id}</span></td>
                    <td className="p-2"><StateBadge state={s.state} /></td>
                    <td className="p-2">{s.subscription_ends_at ? formatDay(s.subscription_ends_at, i18n.language) : t('superAdmin.noEndDate')}</td>
                    <td className="p-2" dir="ltr">{s.students_count} / {s.max_students ?? t('superAdmin.unlimited')}</td>
                    <td className="p-2"><Link to={`/super-admin/schools/${s.id}`} className="rounded border px-3 py-1">{t('superAdmin.open')}</Link></td>
                  </tr>
                ))}
                {rows.length === 0 && <tr><td colSpan={5} className="p-4 text-center text-slate-500">{t('superAdmin.none')}</td></tr>}
              </tbody>
            </table>
          </div>
          <div className="flex items-center justify-between text-sm">
            <span className="text-slate-600">{t('superAdmin.total', { count: data.meta.total })}</span>
            <span className="flex items-center gap-2">
              <InteractiveHoverButton variant="outline" type="button" disabled={page <= 1} className="px-3 py-1 disabled:opacity-40" onClick={() => setPage(page - 1)}>{t('admin.actions.prev')}</InteractiveHoverButton>
              <span dir="ltr">{data.meta.current_page} / {data.meta.last_page}</span>
              <InteractiveHoverButton variant="outline" type="button" disabled={page >= data.meta.last_page} className="px-3 py-1 disabled:opacity-40" onClick={() => setPage(page + 1)}>{t('admin.actions.next')}</InteractiveHoverButton>
            </span>
          </div>
        </>
      )}
    </section>
  )
}
