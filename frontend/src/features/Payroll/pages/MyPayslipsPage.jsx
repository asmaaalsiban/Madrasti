import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Breakdown from '../components/Breakdown'
import CommitmentCard from '../components/CommitmentCard'
import ThisMonthCard from '../components/ThisMonthCard'
import SalaryDonut from '../components/SalaryDonut'
import Money from '../../Finance/components/Money'

// "My salary": the current contract, the last paid month with how it was calculated, a chart of the months, and the history.
export default function MyPayslipsPage({ base }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(null)
  const { data, isLoading } = useQuery({ queryKey: ['payroll', 'mine'], queryFn: async () => (await api.get('/payroll/payslips/mine')).data })
  const mine = useQuery({ queryKey: ['payroll', 'my-contract'], queryFn: async () => (await api.get('/payroll/my-contract')).data })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  const [latest, ...older] = data.data
  const c = mine.data?.data
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('payroll.mineTitle')}</h1>

      <ThisMonthCard currency={data.currency} />

      {latest && (
        <div className="space-y-3 rounded-lg border bg-white p-4" aria-label={t('payroll.latestMonth')}>
          <div className="flex items-center justify-between text-sm text-slate-600"><span>{t('payroll.netOf', { month: latest.month })}</span><span className="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800">{t('payroll.paid')}</span></div>
          <p className="text-3xl font-bold"><Money value={latest.net} currency={data.currency} /></p>
          <div className="grid grid-cols-3 gap-2 text-center text-sm">
            <div className="rounded border p-2"><p className="text-xs text-slate-500">{t('payroll.cols.base')}</p><Money value={latest.base_salary} currency="" /></div>
            <div className="rounded border p-2"><p className="text-xs text-slate-500">{t('payroll.cols.additions')}</p><span className="text-green-700" dir="ltr">+{Number(latest.additions).toFixed(2)}</span></div>
            <div className="rounded border p-2"><p className="text-xs text-slate-500">{t('payroll.cols.deductions')}</p><span className="text-red-700" dir="ltr">−{Number(latest.deductions).toFixed(2)}</span></div>
          </div>
          <p className="pt-1 text-sm font-medium">{t('payroll.howCalculated')}</p>
          <Breakdown payslip={latest} currency={data.currency} />
          <Link className="inline-block text-sm text-brand underline" to={`${base}/payslips/${latest.id}`}>{t('payroll.payslip')}</Link>
        </div>
      )}

      {c && (
        <div className="space-y-1 rounded-lg border bg-white p-4" aria-label={t('payroll.mySalary')}>
          <p className="text-sm text-slate-500">{t('payroll.mySalary')}</p>
          <p className="text-2xl font-bold"><Money value={c.base_salary} currency={mine.data.currency} /></p>
          <p className="text-sm text-slate-600">{t('payroll.payMethod')}: {c.payment_method ? t(`finance.methods.${c.payment_method}`) : '—'}</p>
          <p className="text-sm text-slate-600" dir="ltr">{c.starts_on} → {c.ends_on ?? '—'}</p>
          {c.items.map((i, k) => <p key={k} className="text-sm">{t(`payroll.kinds.${i.kind}`)}: {i.label} <span dir="ltr">{i.kind === 'deduction' ? '−' : '+'}{i.amount}</span></p>)}
        </div>
      )}

      <CommitmentCard currency={data.currency} />
      <SalaryDonut rows={data.data} currency={data.currency} />

      {older.length > 0 && <h2 className="font-bold">{t('payroll.previousMonths')}</h2>}
      <ul className="space-y-2">
        {older.map((p) => (
          <li key={p.id} className="rounded-lg border bg-white p-3">
            <button type="button" aria-expanded={open === p.id} className="flex w-full flex-wrap items-center justify-between gap-2 text-start" onClick={() => setOpen(open === p.id ? null : p.id)}>
              <span><b dir="ltr">{p.month}</b> <span className="text-xs text-slate-500">({p.paid_on})</span></span>
              <Money value={p.net} currency={data.currency} />
            </button>
            {open === p.id && (
              <div className="mt-3 space-y-2">
                <Breakdown payslip={p} currency={data.currency} />
                <Link className="text-sm text-brand underline" to={`${base}/payslips/${p.id}`}>{t('payroll.payslip')}</Link>
              </div>
            )}
          </li>
        ))}
        {data.data.length === 0 && <li className="rounded border bg-white p-4 text-center text-slate-500">{t('payroll.noMine')}</li>}
      </ul>
    </section>
  )
}
