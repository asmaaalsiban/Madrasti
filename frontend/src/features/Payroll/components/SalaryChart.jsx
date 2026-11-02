import { useTranslation } from 'react-i18next'

// The net salary of the last months as bars, oldest on the left; bonuses stack above the base, a deduction shows as a red cap.
export default function SalaryChart({ rows, currency }) {
  const { t } = useTranslation()
  const months = [...rows].slice(0, 12).reverse()
  if (months.length === 0) return null
  const max = Math.max(...months.map((m) => Number(m.base_salary) + Number(m.additions)), 1)
  const W = 100 / months.length
  const h = (v) => (Number(v) / max) * 100
  return (
    <figure className="rounded-lg border bg-white p-4" aria-label={t('payroll.chartTitle')}>
      <figcaption className="mb-2 text-sm font-bold">{t('payroll.chartTitle')}</figcaption>
      <svg viewBox="0 0 100 62" className="w-full" dir="ltr" role="img" aria-label={t('payroll.chartTitle')}>
        {[0, 0.5, 1].map((g) => <line key={g} x1="0" x2="100" y1={50 - g * 46} y2={50 - g * 46} stroke="#e2e8f0" strokeWidth="0.2" />)}
        {months.map((m, i) => {
          const base = h(m.base_salary) * 0.46
          const add = h(m.additions) * 0.46
          const ded = h(m.deductions) * 0.46
          const x = i * W + W * 0.2
          const w = W * 0.6
          return (
            <g key={m.id}>
              <rect x={x} y={50 - base} width={w} height={base} fill="#2563eb" rx="0.6"><title>{`${m.month}: ${t('payroll.cols.base')} ${m.base_salary}`}</title></rect>
              {add > 0 && <rect x={x} y={50 - base - add} width={w} height={add} fill="#16a34a" rx="0.6"><title>{`${m.month}: ${t('payroll.cols.additions')} +${m.additions}`}</title></rect>}
              {ded > 0 && <rect x={x} y={50 - ded} width={w} height={ded} fill="#dc2626" opacity="0.85" rx="0.6"><title>{`${m.month}: ${t('payroll.cols.deductions')} −${m.deductions}`}</title></rect>}
              <text x={x + w / 2} y={55} fontSize="2.6" textAnchor="middle" fill="#64748b">{m.month.slice(5)}</text>
              <text x={x + w / 2} y={59} fontSize="2.4" textAnchor="middle" fill="#0f172a" fontWeight="bold">{Number(m.net).toFixed(0)}</text>
            </g>
          )
        })}
      </svg>
      <ul className="mt-1 flex flex-wrap gap-3 text-xs text-slate-600">
        <li><span className="me-1 inline-block h-2 w-2 rounded-sm bg-blue-600" />{t('payroll.cols.base')}</li>
        <li><span className="me-1 inline-block h-2 w-2 rounded-sm bg-green-600" />{t('payroll.cols.additions')}</li>
        <li><span className="me-1 inline-block h-2 w-2 rounded-sm bg-red-600" />{t('payroll.cols.deductions')}</li>
        <li className="ms-auto">{currency}</li>
      </ul>
    </figure>
  )
}
