import { useState } from 'react'
import { useTranslation } from 'react-i18next'

const pad = (n) => String(n).padStart(2, '0')
const MONTHS = (() => {
  try {
    const f = new Intl.DateTimeFormat('ar-JO-u-nu-latn', { month: 'long' })
    return Array.from({ length: 12 }, (_, i) => f.format(new Date(2026, i, 1)))
  } catch {
    return Array.from({ length: 12 }, (_, i) => String(i + 1))
  }
})()

// The browser draws a native date as mm/dd/yyyy from left to right whatever the page language. In Arabic we show
// day - month - year from right to left instead (day first, at the right edge); in English the native field is kept.
function Parts({ value, onChange, withDay, min, max, className, required, disabled, id, ...rest }) {
  const { t } = useTranslation()
  const [y0, m0, d0] = (value || '').split('-')
  const [part, setPart] = useState({ y: y0 || '', m: m0 || '', d: d0 || '' })
  // Follow the value when it is changed from outside (a reset, a loaded record).
  const [seen, setSeen] = useState(value)
  if (seen !== value) {
    setSeen(value)
    setPart({ y: y0 || '', m: m0 || '', d: d0 || '' })
  }
  const [nowYear] = useState(() => new Date().getFullYear())
  const from = min ? Number(String(min).slice(0, 4)) : nowYear - 5
  const to = max ? Number(String(max).slice(0, 4)) : nowYear + 10
  const years = Array.from({ length: Math.max(1, to - from + 1) }, (_, i) => from + i)
  if (part.y && !years.includes(Number(part.y))) years.push(Number(part.y))
  const emit = (next) => {
    setPart(next)
    const complete = next.y && next.m && (!withDay || next.d)
    let v = ''
    if (complete) {
      const last = new Date(Number(next.y), Number(next.m), 0).getDate()
      v = withDay ? `${next.y}-${next.m}-${pad(Math.min(Number(next.d), last))}` : `${next.y}-${next.m}`
    }
    onChange({ target: { value: v } })
  }
  const box = 'rounded border bg-white p-1.5'
  return (
    <span dir="rtl" role="group" id={id} aria-label={rest['aria-label']} className={`flex items-center gap-1 ${className ?? ''}`}>
      {withDay && (
        <select aria-label={t('dateInput.day')} required={required} disabled={disabled} className={`${box} w-16`} value={part.d ? String(Number(part.d)) : ''} onChange={(e) => emit({ ...part, d: e.target.value ? pad(e.target.value) : '' })}>
          <option value="">{t('dateInput.day')}</option>
          {Array.from({ length: 31 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}
        </select>
      )}
      <select aria-label={t('dateInput.month')} required={required} disabled={disabled} className={`${box} min-w-0 flex-1`} value={part.m ? String(Number(part.m)) : ''} onChange={(e) => emit({ ...part, m: e.target.value ? pad(e.target.value) : '' })}>
        <option value="">{t('dateInput.month')}</option>
        {MONTHS.map((name, i) => <option key={i} value={i + 1}>{`${i + 1} · ${name}`}</option>)}
      </select>
      <select aria-label={t('dateInput.year')} required={required} disabled={disabled} className={`${box} w-24`} value={part.y} onChange={(e) => emit({ ...part, y: e.target.value })}>
        <option value="">{t('dateInput.year')}</option>
        {years.sort((a, b) => a - b).map((y) => <option key={y} value={y}>{y}</option>)}
      </select>
    </span>
  )
}

export function DateInput(props) {
  const { i18n } = useTranslation()
  if (i18n.language !== 'ar') return <input type="date" {...props} />
  return <Parts withDay {...props} />
}

export function MonthInput(props) {
  const { i18n } = useTranslation()
  if (i18n.language !== 'ar') return <input type="month" {...props} />
  return <Parts {...props} />
}
