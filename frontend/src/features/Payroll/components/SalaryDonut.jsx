import { useState } from 'react'
import { useTranslation } from 'react-i18next'

const COLORS = { net: '#2563eb', deductions: '#dc2626', base: '#2563eb', additions: '#16a34a' }
const R = 40
const C = 2 * Math.PI * R

// A donut of one month's pay. The whole circle is what the person earned (salary + additions); the red slice is what was taken off it
// (absence, lateness, manual deductions) and the blue part is what they receive, shown in the middle.
// The first chip is this month so far (an estimate); the others are the paid months.
export default function SalaryDonut({ rows, estimate = null, currency }) {
  const { t } = useTranslation()
  const [picked, setPicked] = useState(null)
  const months = [...(estimate ? [{ id: 'now', month: estimate.month, base_salary: estimate.base, additions: estimate.additions, deductions: estimate.deductions, net: estimate.net, estimate: true }] : []), ...rows.slice(0, 12)]
  if (months.length === 0) return null
  const current = months.find((m) => m.id === picked) ?? months[0]
  const base = Number(current.base_salary)
  const additions = Number(current.additions)
  const deductions = Number(current.deductions)
  const gross = base + additions
  const net = Number(current.net)
  const whole = gross || 1
  const taken = Math.min(deductions, gross)
  const slices = [
    { key: 'net', value: Math.max(gross - taken, 0), label: t('payroll.cols.net') },
    { key: 'deductions', value: taken, label: t('payroll.cols.deductions') },
  ].filter((s) => s.value > 0)
  const rowsList = [
    { key: 'base', label: t('payroll.cols.base'), text: base.toFixed(2), pct: Math.round((base / whole) * 100) },
    { key: 'additions', label: t('payroll.cols.additions'), text: `+${additions.toFixed(2)}`, pct: Math.round((additions / whole) * 100) },
    { key: 'deductions', label: t('payroll.cols.deductions'), text: `−${deductions.toFixed(2)}`, pct: Math.round((taken / whole) * 100) },
    { key: 'net', label: t('payroll.cols.net'), text: net.toFixed(2), pct: Math.round((Math.max(gross - taken, 0) / whole) * 100) },
  ]
  let offset = 0
  return (
    <figure className="space-y-3 rounded-lg border bg-white p-4" aria-label={t('payroll.chartTitle')}>
      <figcaption className="flex flex-wrap items-center justify-between gap-2 text-sm font-bold">
        {t('payroll.chartTitle')}
        {current.estimate && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-normal text-amber-800">{t('payroll.estimate')}</span>}
      </figcaption>
      <div className="flex flex-wrap items-center justify-center gap-6">
        <svg viewBox="0 0 100 100" className="h-44 w-44 -rotate-90" role="img" aria-label={t('payroll.chartTitle')}>
          <circle cx="50" cy="50" r={R} fill="none" stroke="#e2e8f0" strokeWidth="14" />
          {slices.map((s) => {
            const len = (s.value / whole) * C
            const el = <circle key={s.key} cx="50" cy="50" r={R} fill="none" stroke={COLORS[s.key]} strokeWidth="14" strokeDasharray={`${Math.max(len - (slices.length > 1 ? 0.6 : 0), 0)} ${C}`} strokeDashoffset={-offset}><title>{`${s.label}: ${s.value.toFixed(2)}`}</title></circle>
            offset += len
            return el
          })}
          <g transform="rotate(90 50 50)" textAnchor="middle">
            <text x="50" y="45" fontSize="6" fill="#64748b">{t('payroll.cols.net')}</text>
            <text x="50" y="56" fontSize="11" fontWeight="bold" fill="#0f172a" dir="ltr">{net.toFixed(2)}</text>
            <text x="50" y="64" fontSize="5" fill="#64748b">{currency}</text>
          </g>
        </svg>
        <ul className="space-y-2 text-sm">
          {rowsList.map((p) => (
            <li key={p.key} className="flex items-center gap-2">
              <span className="inline-block h-3 w-3 rounded-sm" style={{ background: COLORS[p.key] }} />
              <span className="min-w-20">{p.label}</span>
              <b dir="ltr">{p.text}</b>
              <span className="text-xs text-slate-500" dir="ltr">{p.pct}%</span>
            </li>
          ))}
        </ul>
      </div>
      <p className="text-center text-xs text-slate-500">{t('payroll.chartNote')}</p>
      <div className="flex flex-wrap justify-center gap-1.5" role="tablist" aria-label={t('payroll.pickMonth')}>
        {months.map((m) => (
          <button key={m.id} type="button" role="tab" aria-selected={m.id === current.id} dir="ltr" className={`rounded-full border px-3 py-1 text-xs ${m.id === current.id ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => setPicked(m.id)}>{m.estimate ? `${m.month} ~` : m.month}</button>
        ))}
      </div>
    </figure>
  )
}
