// Small SVG charts for the principal's home page. No chart library: they are a few lines each, follow the school's brand colour
// (currentColor + text-brand) and read in both directions (the numbers stay left-to-right).

import { useGrown } from '../../../components/shared/motion'
import { GREEN } from './levelColor'

// A ring that shows one percentage, with the number in the middle.
export function Ring({ percent, label, color = GREEN }) {
  const R = 36
  const C = 2 * Math.PI * R
  const p = Math.max(0, Math.min(100, Number(percent) || 0))
  const grown = useGrown() // the ring fills from empty
  const shown = grown ? p : 0
  return (
    <svg viewBox="0 0 100 100" className="h-24 w-24 shrink-0" role="img" aria-label={`${label}: ${Math.round(p)}%`}>
      <circle cx="50" cy="50" r={R} fill="none" stroke="#e2e8f0" strokeWidth="11" />
      <circle cx="50" cy="50" r={R} fill="none" stroke={color} strokeWidth="11" strokeLinecap="round" strokeDasharray={`${(shown / 100) * C} ${C}`} transform="rotate(-90 50 50)" style={{ transition: 'stroke-dasharray 1s ease-out' }} />
      <text x="50" y="56" textAnchor="middle" fontSize="20" fontWeight="700" fill="currentColor" direction="ltr">{Math.round(p)}%</text>
    </svg>
  )
}

// A line over time (points are {label, value} from 0 to 100). Dots carry a tooltip.
export function LineChart({ points, label, unit = '%' }) {
  if (points.length === 0) return null
  const W = 300
  const H = 120
  const pad = 14
  const x = (i) => (points.length === 1 ? W / 2 : pad + (i * (W - pad * 2)) / (points.length - 1))
  const y = (v) => H - pad - (Math.max(0, Math.min(100, v)) / 100) * (H - pad * 2)
  const line = points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)} ${y(p.value).toFixed(1)}`).join(' ')
  const area = `${line} L${x(points.length - 1).toFixed(1)} ${H - pad} L${x(0).toFixed(1)} ${H - pad} Z`
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="w-full text-brand" role="img" aria-label={label} preserveAspectRatio="none" style={{ height: 140 }}>
      {[0, 50, 100].map((g) => <line key={g} x1={pad} x2={W - pad} y1={y(g)} y2={y(g)} stroke="#e2e8f0" strokeWidth="1" strokeDasharray="3 3" />)}
      <path d={area} fill="currentColor" opacity="0.1" />
      <path d={line} fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
      {points.map((p, i) => <circle key={p.label} cx={x(i)} cy={y(p.value)} r="3.5" fill="#fff" stroke="currentColor" strokeWidth="2"><title>{`${p.label}: ${p.value}${unit}`}</title></circle>)}
    </svg>
  )
}

// Vertical bars (items are {label, value}), the biggest one fills the height.
export function Columns({ items, label, format = (v) => v }) {
  const max = Math.max(...items.map((i) => i.value), 1)
  const grown = useGrown() // the bars grow from the bottom, one after the other
  return (
    <div role="img" aria-label={label} className="flex h-40 items-end gap-2 text-brand">
      {items.map((i, n) => (
        <div key={i.label} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1" title={`${i.label}: ${format(i.value)}`}>
          <span className="text-[11px] text-slate-600" dir="ltr">{i.value > 0 ? format(i.value) : ''}</span>
          {/* Rounded pill bars: the biggest one is drawn in full colour, the others softer. */}
          <div className="w-full max-w-9 rounded-full bg-current" style={{ height: grown ? `${Math.max((i.value / max) * 100, i.value > 0 ? 6 : 1)}%` : '0%', opacity: i.value > 0 ? (i.value === max ? 1 : 0.35) : 0.15, transition: `height .8s ease-out ${n * 70}ms` }} />
          <span className="w-full truncate text-center text-[11px] text-slate-600" dir="ltr">{i.label}</span>
        </div>
      ))}
    </div>
  )
}

// Horizontal bars (items are {label, value, max?, color?}).
export function Bars({ items, label, suffix = '' }) {
  const top = Math.max(...items.map((i) => i.max ?? i.value), 1)
  const grown = useGrown()
  return (
    <ul aria-label={label} className="space-y-2 text-brand">
      {items.map((i, n) => (
        <li key={i.label} className="text-sm">
          <div className="flex justify-between gap-2"><span className="truncate text-ink"><bdi>{i.label}</bdi></span><span className="text-slate-600" dir="ltr">{i.value}{suffix}</span></div>
          <div className="mt-1 h-2.5 overflow-hidden rounded-full bg-slate-100">
            <div className="h-full rounded-full" style={{ width: grown ? `${Math.min((i.value / top) * 100, 100)}%` : '0%', background: i.color ?? 'currentColor', transition: `width .8s ease-out ${n * 70}ms` }} />
          </div>
        </li>
      ))}
    </ul>
  )
}

