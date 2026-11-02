import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Money from '../../Finance/components/Money'

// The signed-in person's own paid payslips.
export default function MyPayslipsPage({ base }) {
  const { t } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['payroll', 'mine'], queryFn: async () => (await api.get('/payroll/payslips/mine')).data })
  const mine = useQuery({ queryKey: ['payroll', 'my-contract'], queryFn: async () => (await api.get('/payroll/my-contract')).data })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('payroll.mineTitle')}</h1>
      {mine.data?.data && (
        <div className="space-y-1 rounded-lg border bg-white p-4" aria-label={t('payroll.mySalary')}>
          <p className="text-sm text-slate-500">{t('payroll.mySalary')}</p>
          <p className="text-2xl font-bold"><Money value={mine.data.data.base_salary} currency={mine.data.currency} /></p>
          <p className="text-sm text-slate-600">{t('payroll.payMethod')}: {mine.data.data.payment_method ? t(`finance.methods.${mine.data.data.payment_method}`) : '—'}</p>
          <p className="text-sm text-slate-600" dir="ltr">{mine.data.data.starts_on} → {mine.data.data.ends_on ?? '—'}</p>
          {mine.data.data.items.map((i, k) => <p key={k} className="text-sm">{t(`payroll.kinds.${i.kind}`)}: {i.label} <span dir="ltr">{i.kind === 'deduction' ? '−' : '+'}{i.amount}</span></p>)}
        </div>
      )}
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
