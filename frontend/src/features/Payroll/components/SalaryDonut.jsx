import { useState } from 'react'
import { useTranslation } from 'react-i18next'

const COLORS = { base: '#2563eb', additions: '#16a34a', deductions: '#dc2626' }
const R = 40
const C = 2 * Math.PI * R

// A donut of one month's salary: the base, what was added and what was taken off, with the net in the middle.
// Pick the month with the chips under it.
export default function SalaryDonut({ rows, currency }) {
  const { t } = useTranslation()
  const [picked, setPicked] = useState(null)
  if (rows.length === 0) return null
  const months = rows.slice(0, 12)
  const current = months.find((m) => m.id === picked) ?? months[0]
  const parts = [
    { key: 'base', label: t('payroll.cols.base'), value: Number(current.base_salary), sign: '' },
    { key: 'additions', label: t('payroll.cols.additions'), value: Number(current.additions), sign: '+' },
    { key: 'deductions', label: t('payroll.cols.deductions'), value: Number(current.deductions), sign: '−' },
  ]
  const total = parts.reduce((s, p) => s + p.value, 0) || 1
  let offset = 0
  return (
    <figure className="space-y-3 rounded-lg border bg-white p-4" aria-label={t('payroll.chartTitle')}>
      <figcaption className="text-sm font-bold">{t('payroll.chartTitle')}</figcaption>
      <div className="flex flex-wrap items-center justify-center gap-6">
        <svg viewBox="0 0 100 100" className="h-44 w-44 -rotate-90" role="img" aria-label={t('payroll.chartTitle')}>
          <circle cx="50" cy="50" r={R} fill="none" stroke="#e2e8f0" strokeWidth="14" />
          {parts.filter((p) => p.value > 0).map((p) => {
            const len = (p.value / total) * C
            const el = <circle key={p.key} cx="50" cy="50" r={R} fill="none" stroke={COLORS[p.key]} strokeWidth="14" strokeDasharray={`${Math.max(len - 0.6, 0)} ${C}`} strokeDashoffset={-offset}><title>{`${p.label}: ${p.sign}${p.value.toFixed(2)}`}</title></circle>
            offset += len
            return el
          })}
          <g transform="rotate(90 50 50)" textAnchor="middle">
            <text x="50" y="45" fontSize="6" fill="#64748b">{t('payroll.cols.net')}</text>
            <text x="50" y="56" fontSize="11" fontWeight="bold" fill="#0f172a" dir="ltr">{Number(current.net).toFixed(2)}</text>
            <text x="50" y="64" fontSize="5" fill="#64748b">{currency}</text>
          </g>
        </svg>
        <ul className="space-y-2 text-sm">
          {parts.map((p) => (
            <li key={p.key} className="flex items-center gap-2">
              <span className="inline-block h-3 w-3 rounded-sm" style={{ background: COLORS[p.key] }} />
              <span className="min-w-20">{p.label}</span>
              <b dir="ltr">{p.sign}{p.value.toFixed(2)}</b>
              <span className="text-xs text-slate-500" dir="ltr">{Math.round((p.value / total) * 100)}%</span>
            </li>
          ))}
        </ul>
      </div>
      <div className="flex flex-wrap justify-center gap-1.5" role="tablist" aria-label={t('payroll.pickMonth')}>
        {months.map((m) => (
          <button key={m.id} type="button" role="tab" aria-selected={m.id === current.id} dir="ltr" className={`rounded-full border px-3 py-1 text-xs ${m.id === current.id ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => setPicked(m.id)}>{m.month}</button>
        ))}
      </div>
    </figure>
  )
}
