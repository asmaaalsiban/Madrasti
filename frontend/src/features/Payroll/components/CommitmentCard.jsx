import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// "My commitment this month": a score per measure and what it would add to or take off the salary.
export default function CommitmentCard({ currency }) {
  const { t } = useTranslation()
  const { data } = useQuery({ queryKey: ['payroll', 'commitment'], queryFn: async () => (await api.get('/payroll/commitment')).data })
  if (!data?.scores) return null
  const rows = Object.entries(data.scores).filter(([, s]) => s.score !== null)
  if (rows.length === 0) return null
  const line = (code) => data.lines.find((l) => l.code === code)
  return (
    <div className="space-y-3 rounded-lg border bg-white p-4" aria-label={t('payroll.myCommitment')}>
      <p className="font-bold">{t('payroll.myCommitment')}</p>
      {data.lines.filter((l) => l.code === 'absence').map((l) => <p key="absence" className="text-sm text-red-700">{t('payroll.auto.absence.deduction', { score: l.score })}: <span dir="ltr">−{l.amount} {currency}</span></p>)}
      {rows.map(([code, s]) => {
        const l = line(code)
        return (
          <div key={code} className="space-y-1">
            <div className="flex items-center justify-between text-sm"><span>{t(`payroll.rulesCodes.${code}`)} <span className="text-xs text-slate-500">({s.done}/{s.expected})</span></span><b dir="ltr">{s.score}%</b></div>
            <div className="h-2 overflow-hidden rounded bg-slate-100"><div className={`h-full ${s.score >= 90 ? 'bg-green-600' : s.score >= 70 ? 'bg-amber-500' : 'bg-red-500'}`} style={{ width: `${s.score}%` }} /></div>
            {l && <p className={`text-xs ${l.kind === 'bonus' ? 'text-green-700' : 'text-red-700'}`}>{t(`payroll.auto.${code}.${l.kind}`, { score: l.score })}: <span dir="ltr">{l.kind === 'bonus' ? '+' : '−'}{l.amount} {currency}</span></p>}
          </div>
        )
      })}
    </div>
  )
}
