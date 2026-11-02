import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Money from '../../Finance/components/Money'

// The signed-in person's own paid payslips.
export default function MyPayslipsPage({ base }) {
  const { t } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['payroll', 'mine'], queryFn: async () => (await api.get('/payroll/payslips/mine')).data })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('payroll.mineTitle')}</h1>
      <ul className="space-y-2">
        {data.data.map((p) => (
          <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-white p-3">
            <span><b dir="ltr">{p.month}</b> · <Money value={p.net} currency={data.currency} /> <span className="text-xs text-slate-500">({p.paid_on})</span></span>
            <Link className="text-brand underline" to={`${base}/payslips/${p.id}`}>{t('payroll.payslip')}</Link>
          </li>
        ))}
        {data.data.length === 0 && <li className="rounded border bg-white p-4 text-center text-slate-500">{t('payroll.noMine')}</li>}
      </ul>
    </section>
  )
}
