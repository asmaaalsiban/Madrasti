import { useTranslation } from 'react-i18next'
import Money from '../../Finance/components/Money'
import { itemLabel } from '../itemLabel'

// "How it was calculated": the base salary, then every bonus and deduction with its reason, grouped by category (private lessons, clubs).
export default function Breakdown({ payslip, currency }) {
  const { t } = useTranslation()
  const groups = [...new Set(payslip.items.map((i) => i.category ?? ''))]
  const sign = (i) => (i.kind === 'deduction' ? '−' : '+')
  return (
    <div className="space-y-2 text-sm">
      <div className="flex justify-between border-b pb-1"><span>{t('payroll.cols.base')}</span><Money value={payslip.base_salary} currency={currency} /></div>
      {groups.map((g) => (
        <div key={g || 'general'} className="space-y-1">
          {g && <p className="text-xs font-medium text-slate-500">{t(`payroll.categories.${g}`)}</p>}
          {payslip.items.filter((i) => (i.category ?? '') === g).map((i, k) => (
            <div key={k} className={`flex justify-between ${i.kind === 'deduction' ? 'text-red-700' : 'text-green-800'}`}><span>{itemLabel(t, i)}</span><span dir="ltr">{sign(i)}{Number(i.amount).toFixed(2)}</span></div>
          ))}
        </div>
      ))}
      <div className="flex justify-between border-t pt-1 font-bold"><span>{t('payroll.cols.net')}</span><Money value={payslip.net} currency={currency} /></div>
    </div>
  )
}
