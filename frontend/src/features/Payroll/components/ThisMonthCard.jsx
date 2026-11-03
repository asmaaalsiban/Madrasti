import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Money from '../../Finance/components/Money'

const shortDate = (d) => d.slice(5).split('-').reverse().join('/')

// "This month so far": the salary, the days missed with the deduction they cost, and what the month would pay if it were paid now.
export default function ThisMonthCard({ currency }) {
  const { t } = useTranslation()
  const { data } = useQuery({ queryKey: ['payroll', 'commitment'], queryFn: async () => (await api.get('/payroll/commitment')).data })
  const e = data?.estimate
  if (!e) return null
  const absence = data.lines.find((l) => l.code === 'absence')
  const dates = data.scores?.punctuality?.absent_dates ?? []
  const others = data.lines.filter((l) => l.code !== 'absence')
  return (
    <div className="space-y-3 rounded-lg border bg-white p-4" aria-label={t('payroll.thisMonth')}>
      <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
        <span className="font-bold text-deep-harbor">{t('payroll.thisMonth')} <bdi dir="ltr">({data.month})</bdi></span>
        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{t('payroll.estimate')}</span>
      </div>
      <p className="text-3xl font-bold"><Money value={e.net} currency={currency} /></p>
      <div className="grid grid-cols-3 gap-2 text-center text-sm">
        <div className="rounded border p-2"><p className="text-xs text-slate-500">{t('payroll.cols.base')}</p><Money value={e.base} currency="" /></div>
        <div className="rounded border p-2"><p className="text-xs text-slate-500">{t('payroll.cols.additions')}</p><span className="text-green-700" dir="ltr">+{Number(e.additions).toFixed(2)}</span></div>
        <div className="rounded border p-2"><p className="text-xs text-slate-500">{t('payroll.cols.deductions')}</p><span className="text-red-700" dir="ltr">−{Number(e.deductions).toFixed(2)}</span></div>
      </div>
      {absence && (
        <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
          <p className="font-bold">{t('payroll.absenceDeducted', { count: absence.score })}: <span dir="ltr">−{absence.amount} {currency}</span></p>
          {dates.length > 0 && <p className="mt-1 text-xs">{t('payroll.absentOn')}: {dates.map((d) => <bdi key={d} dir="ltr" className="me-2 inline-block rounded bg-white px-2 py-0.5">{shortDate(d)}</bdi>)}</p>}
        </div>
      )}
      {others.map((l) => <p key={l.code} className={`text-sm ${l.kind === 'bonus' ? 'text-green-700' : 'text-red-700'}`}>{t(`payroll.auto.${l.code}.${l.kind}`, { score: l.score })}: <span dir="ltr">{l.kind === 'bonus' ? '+' : '−'}{l.amount} {currency}</span></p>)}
      <p className="text-xs text-slate-500">{t('payroll.estimateNote')}</p>
    </div>
  )
}
