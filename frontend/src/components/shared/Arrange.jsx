import { useTranslation } from 'react-i18next'
import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp, Check, RotateCcw, SlidersHorizontal } from 'lucide-react'

// The switch that puts a screen in "arrange" mode, and the way back to the default order.
export function ArrangeBar({ editing, onToggle, onReset, custom, className = '' }) {
  const { t } = useTranslation()
  return (
    <div className={`flex flex-wrap items-center gap-2 ${className}`}>
      <button type="button" aria-pressed={editing} className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm ${editing ? 'bg-brand text-white' : 'bg-white text-brand'}`} onClick={onToggle}>
        {editing ? <Check size={14} aria-hidden /> : <SlidersHorizontal size={14} aria-hidden />}{editing ? t('arrange.done') : t('arrange.start')}
      </button>
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
