import { useTranslation } from 'react-i18next'
import { useState } from 'react'
import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp, Check, Palette, RotateCcw, SlidersHorizontal } from 'lucide-react'
import { PALETTE, SIZES } from '../../features/Layout/arrange'
import { InteractiveHoverButton } from '../ui/interactive-hover-button'

// The switch that puts a screen in "arrange" mode, and the way back to the default order.
export function ArrangeBar({ editing, onToggle, onReset, custom, size, onSize, className = '' }) {
  const { t } = useTranslation()
  return (
    <div className={`flex flex-wrap items-center gap-2 ${className}`}>
      <button type="button" aria-pressed={editing} className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm ${editing ? 'bg-brand text-white' : 'bg-white text-brand'}`} onClick={onToggle}>
        {editing ? <Check size={14} aria-hidden /> : <SlidersHorizontal size={14} aria-hidden />}{editing ? t('arrange.done') : t('arrange.start')}
      </button>
      {editing && onSize && <SizePicker value={size} onChange={onSize} />}
      {editing && custom && <button type="button" className="inline-flex items-center gap-2 rounded-full border bg-white px-3 py-1 text-sm text-slate-700" onClick={onReset}><RotateCcw size={14} aria-hidden />{t('arrange.reset')}</button>}
      {editing && <span className="text-xs text-slate-500">{t('arrange.help')}</span>}
    </div>
  )
}

// Two small buttons that move an item earlier or later. `vertical`: up/down (lists); otherwise left/right that follow the language direction.
export function MoveButtons({ label, onEarlier, onLater, canEarlier = true, canLater = true, vertical = false, className = '' }) {
  const { t, i18n } = useTranslation()
  const rtl = i18n.dir() === 'rtl'
  const Earlier = vertical ? ArrowUp : rtl ? ArrowRight : ArrowLeft
  const Later = vertical ? ArrowDown : rtl ? ArrowLeft : ArrowRight
  const style = 'grid h-7 w-7 place-items-center rounded-full border bg-white text-deep-harbor disabled:opacity-30'
  return (
    <span className={`inline-flex gap-1 ${className}`}>
      <button type="button" className={style} disabled={!canEarlier} aria-label={t('arrange.earlier', { name: label })} onClick={onEarlier}><Earlier size={14} aria-hidden /></button>
      <button type="button" className={style} disabled={!canLater} aria-label={t('arrange.later', { name: label })} onClick={onLater}><Later size={14} aria-hidden /></button>
    </span>
  )
}

// Small / medium / large for the icons of a screen.
export function SizePicker({ value, onChange }) {
  const { t } = useTranslation()
  return (
    <div role="group" aria-label={t('arrange.size')} className="inline-flex items-center gap-1 rounded-full border bg-white p-0.5 text-sm">
      <span className="px-2 text-xs text-slate-500">{t('arrange.size')}</span>
      {SIZES.map((s) => <button key={s} type="button" aria-pressed={value === s} className={`rounded-full px-3 py-0.5 ${value === s ? 'bg-brand text-white' : 'text-slate-700'}`} onClick={() => onChange(s)}>{t(`arrange.sizes.${s}`)}</button>)}
    </div>
  )
}

// The colour of one icon: a button that opens a row of colours, "default" and a free colour picker.
export function ColorButton({ label, value, onChange }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  return (
    <span className="relative inline-block">
      <button type="button" aria-expanded={open} aria-label={t('arrange.colorOf', { name: label })} className="grid h-7 w-7 place-items-center rounded-full border bg-white" onClick={() => setOpen(!open)}>
        {value ? <span aria-hidden className="h-4 w-4 rounded-full border" style={{ background: value }} /> : <Palette size={14} aria-hidden />}
      </button>
      {open && (
        <span role="group" aria-label={t('arrange.colorOf', { name: label })} className="absolute start-0 top-9 z-20 flex w-44 flex-wrap gap-1.5 rounded-lg border bg-white p-2 shadow-lg">
          {PALETTE.map((c) => <button key={c} type="button" aria-label={c} aria-pressed={value === c} className={`h-6 w-6 rounded-full border-2 ${value === c ? 'border-deep-harbor' : 'border-white'}`} style={{ background: c }} onClick={() => { onChange(c); setOpen(false) }} />)}
          <label className="flex w-full items-center gap-2 text-xs text-slate-700">{t('arrange.custom')}<input type="color" aria-label={t('arrange.custom')} value={value ?? '#2563eb'} onChange={(e) => onChange(e.target.value)} /></label>
          <InteractiveHoverButton variant="outline" type="button" className="w-full px-2 py-0.5 text-xs text-slate-700" onClick={() => { onChange(null); setOpen(false) }}>{t('arrange.defaultColor')}</InteractiveHoverButton>
        </span>
      )}
    </span>
  )
}
