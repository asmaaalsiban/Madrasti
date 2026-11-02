import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Money from '../../Finance/components/Money'
import { useAuth } from '../../Auth/AuthProvider'
import { MonthInput } from '../../../components/shared/DateInput'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// The monthly payrolls: the accountant opens a month, the principal approves it.
export default function PayrollPage({ base }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const navigate = useNavigate()
  const client = useQueryClient()
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7))
  const [error, setError] = useState('')
  const { data, isLoading } = useQuery({ queryKey: ['payroll', 'runs'], queryFn: async () => (await api.get('/payroll/runs')).data })
  const prepare = useMutation({
    mutationFn: () => api.post('/payroll/runs', { month }),
    onSuccess: (res) => { client.invalidateQueries({ queryKey: ['payroll'] }); navigate(`${base}/payroll/${res.data.data.id}`) },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('payroll.title')}</h1>
        <span className="flex flex-wrap gap-2"><Link to={`${base}/payroll/templates`} className="rounded border px-3 py-1.5 text-sm text-brand">{t('payroll.templatesTitle')}</Link><Link to={`${base}/payroll/rules`} className="rounded border px-3 py-1.5 text-sm text-brand">{t('payroll.rulesTitle')}</Link><Link to={`${base}/payroll/contracts`} className="rounded border px-3 py-1.5 text-sm text-brand">{t('payroll.contractsTitle')}</Link></span>
      </div>
      <p className="text-sm text-slate-600">{t('payroll.help')}</p>
      {user?.staff_type === 'accountant' && (
        <form className="flex flex-wrap items-end gap-2 rounded-lg border bg-white p-3" onSubmit={(e) => { e.preventDefault(); setError(''); prepare.mutate() }}>
          <label className="text-sm">{t('payroll.month')}<MonthInput required className="mt-1 block rounded border p-2" value={month} onChange={(e) => setMonth(e.target.value)} /></label>
          <button type="submit" disabled={prepare.isPending} className="rounded bg-brand px-4 py-2 text-white disabled:opacity-50">{t('payroll.prepare')}</button>
          {error && <p role="alert" className="w-full text-sm text-red-700">{error}</p>}
        </form>
      )}
      <div className="overflow-x-auto rounded-lg border bg-white">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-50"><tr>{['month', 'people', 'net', 'state'].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`payroll.cols.${c}`)}</th>)}<th /></tr></thead>
          <tbody>
            {data.data.map((r) => (
              <tr key={r.id} className="border-t">
                <td className="p-2 font-medium" dir="ltr">{r.month}</td>
                <td className="p-2">{r.payslips_count}</td>
                <td className="p-2"><Money value={r.total_net} currency={data.currency} /></td>
                <td className="p-2"><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{t(`payroll.runStatus.${r.status}`)}</span></td>
                <td className="p-2 text-end"><Link className="text-brand underline" to={`${base}/payroll/${r.id}`}>{t('payroll.open')}</Link></td>
              </tr>
            ))}
            {data.data.length === 0 && <tr><td colSpan={5} className="p-4 text-center text-slate-500">{t('payroll.noRuns')}</td></tr>}
          </tbody>
        </table>
      </div>
    </section>
  )
}
