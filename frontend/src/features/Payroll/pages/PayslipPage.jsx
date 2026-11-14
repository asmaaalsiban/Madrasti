import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { itemLabel } from '../itemLabel'
import Money from '../../Finance/components/Money'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// A payslip on one A4 page: print it or save it as PDF.
export default function PayslipPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const { data, isLoading } = useQuery({ queryKey: ['payroll', 'payslip', id], queryFn: async () => (await api.get(`/payroll/payslips/${id}`)).data })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  const s = data.data
  const c = data.currency
  const lines = (kinds) => s.items.filter((i) => kinds.includes(i.kind))
  return (
    <section className="space-y-3">
      <InteractiveHoverButton type="button" className="px-4 py-1.5 print:hidden" onClick={() => window.print()}>{t('payroll.print')}</InteractiveHoverButton>
      <article className="print-area mx-auto max-w-2xl space-y-4 rounded-lg border bg-white p-6 text-slate-900 shadow-sm print:max-w-none print:rounded-none print:border-0 print:p-8 print:shadow-none" aria-label={t('payroll.payslip')}>
        <header className="border-b pb-3 text-center"><h1 className="text-xl font-bold">{data.school}</h1><p className="font-medium">{t('payroll.payslipOf', { month: s.month })}</p></header>
        <dl className="grid grid-cols-2 gap-2 text-sm"><dt className="text-slate-500">{t('payroll.cols.name')}</dt><dd className="font-medium">{s.user.name}</dd>{s.user.staff_type && <><dt className="text-slate-500">{t('payroll.cols.position')}</dt><dd>{t(`staff.types.${s.user.staff_type}`)}</dd></>}</dl>
        <table className="w-full text-sm">
          <tbody>
            <tr className="border-b"><td className="py-1">{t('payroll.cols.base')}</td><td className="py-1 text-end"><Money value={s.base_salary} currency={c} /></td></tr>
            {lines(['allowance', 'bonus']).map((i) => <tr key={i.id} className="border-b text-green-800"><td className="py-1">{t(`payroll.kinds.${i.kind}`)}: {itemLabel(t, i)}</td><td className="py-1 text-end" dir="ltr">+{i.amount}</td></tr>)}
            {lines(['deduction']).map((i) => <tr key={i.id} className="border-b text-red-700"><td className="py-1">{t('payroll.kinds.deduction')}: {itemLabel(t, i)}</td><td className="py-1 text-end" dir="ltr">−{i.amount}</td></tr>)}
            <tr className="font-bold"><td className="py-2">{t('payroll.cols.net')}</td><td className="py-2 text-end"><Money value={s.net} currency={c} /></td></tr>
          </tbody>
        </table>
        {s.paid_on ? <p className="text-sm text-slate-600">{t('payroll.paidLine', { date: s.paid_on, method: t(`finance.methods.${s.payment_method}`) })}{s.reference ? ` · ${s.reference}` : ''}</p> : <p className="text-sm text-amber-700">{t('payroll.notPaidYet')}</p>}
      </article>
    </section>
  )
}
