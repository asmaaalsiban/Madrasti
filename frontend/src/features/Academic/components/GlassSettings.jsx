import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Settings2, X } from 'lucide-react'
import { getSchool } from '../../../tenant/school'
import { useAuth } from '../../Auth/AuthProvider'

// "Adjust glass" for the principal's overview: how frosted the cards look (blur, see-through, colour strength, tint).
// Kept in this browser, per school and per person; nothing is sent to the server. Calm on purpose: no animation here.
export const GLASS_DEFAULTS = { blur: 8, opacity: 0.08, saturation: 1, tint: '#ffffff' }
const LIMITS = { blur: [0, 24], opacity: [0.04, 0.4], saturation: [0.5, 2] }
const PRESETS = { clear: { blur: 4, opacity: 0.05, saturation: 1.1 }, frosted: { blur: 14, opacity: 0.12, saturation: 1.2 }, solid: { blur: 24, opacity: 0.3, saturation: 1 } }

const clamp = (v, [min, max], fallback) => (Number.isFinite(v) ? Math.min(max, Math.max(min, v)) : fallback)
export const normalizeGlass = (v = {}) => ({
  blur: clamp(Number(v.blur), LIMITS.blur, GLASS_DEFAULTS.blur),
  opacity: clamp(Number(v.opacity), LIMITS.opacity, GLASS_DEFAULTS.opacity),
  saturation: clamp(Number(v.saturation), LIMITS.saturation, GLASS_DEFAULTS.saturation),
  tint: /^#[0-9a-f]{6}$/i.test(v.tint ?? '') ? v.tint : GLASS_DEFAULTS.tint,
})

const read = (key) => { try { return JSON.parse(localStorage.getItem(key)) } catch { return null } }
const write = (key, value) => { try { if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, JSON.stringify(value)) } catch { /* storage blocked: it lasts for this page only */ } }

/** The person's glass settings and the CSS variables to put on the overview (empty until they change something). */
export function useGlassSettings() {
  const { user } = useAuth()
  const key = `glass:${getSchool() ?? ''}:${user?.id ?? ''}`
  const [saved, setSaved] = useState(() => read(key))
  const settings = useMemo(() => normalizeGlass(saved ?? {}), [saved])
  const update = useCallback((patch) => { const next = normalizeGlass({ ...settings, ...patch }); setSaved(next); write(key, next) }, [settings, key])
  const reset = useCallback(() => { setSaved(null); write(key, null) }, [key])
  const style = saved ? { '--g-blur': `${settings.blur}px`, '--g-op': settings.opacity, '--g-sat': settings.saturation, '--g-tint': settings.tint } : undefined
  return { settings, custom: Boolean(saved), update, reset, style }
}

/** The button of the pill menu and the panel it opens. */
export default function GlassSettings({ glass }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const trigger = useRef(null)
  const panel = useRef(null)
  const close = useCallback(() => { setOpen(false); trigger.current?.focus() }, [])
  useEffect(() => { if (open) panel.current?.querySelector('input')?.focus() }, [open])
  const { settings, update, reset, custom } = glass
  // On weak devices the page switches the blur off by itself (html[data-lite], see lite.js): the blur slider would show nothing there.
  const lite = typeof document !== 'undefined' && Boolean(document.documentElement.dataset.lite)
  const sliders = [
    ['blur', t('dashboard.glassSettings.blur'), 0, 24, 1, `${Math.round(settings.blur)} px`],
    ['opacity', t('dashboard.glassSettings.opacity'), 0.04, 0.4, 0.01, `${Math.round(settings.opacity * 100)}%`],
    ['saturation', t('dashboard.glassSettings.saturation'), 0.5, 2, 0.05, `${Math.round(settings.saturation * 100)}%`],
  ]
  return (
    <>
      <button ref={trigger} type="button" aria-label={t('dashboard.glassSettings.open')} title={t('dashboard.glassSettings.open')} aria-expanded={open} onClick={() => setOpen(!open)} className="flex h-9 shrink-0 items-center gap-2 rounded-full bg-white/10 px-2.5 text-sm text-white transition-colors hover:bg-white/20 sm:px-3"><Settings2 size={17} aria-hidden /><span className="hidden whitespace-nowrap sm:inline">{t('dashboard.glassSettings.open')}</span></button>
      {open && (
        <aside ref={panel} role="dialog" aria-label={t('dashboard.glassSettings.title')} onKeyDown={(e) => { if (e.key === 'Escape') close() }} className="absolute end-2 top-16 z-30 border border-white/15 bg-[#071a33] max-h-[80vh] w-72 max-w-[calc(100%-1rem)] overflow-auto rounded-3xl p-5 text-white shadow-2xl">
          <div className="flex items-center justify-between gap-2">
            <h2 className="text-base font-bold !text-white">{t('dashboard.glassSettings.title')}</h2>
            <button type="button" aria-label={t('dashboard.glassSettings.close')} onClick={close} className="grid h-8 w-8 place-items-center rounded-full hover:bg-white/15"><X size={16} aria-hidden /></button>
          </div>
          <p className="mt-1 text-xs text-white/60">{t('dashboard.glassSettings.hint')}</p>
          <div className="mt-4 flex gap-1.5" role="group" aria-label={t('dashboard.glassSettings.presets')}>
            {Object.entries(PRESETS).map(([name, value]) => <button key={name} type="button" onClick={() => update(value)} className="flex-1 rounded-lg border border-white/20 py-1.5 text-xs hover:bg-white/15">{t(`dashboard.glassSettings.preset.${name}`)}</button>)}
          </div>
          {lite && <p role="note" className="mt-3 rounded-lg bg-amber-300/15 p-2 text-xs text-amber-100">{t('dashboard.glassSettings.lite')}</p>}
          {sliders.map(([name, label, min, max, step, shown]) => (
            <label key={name} className="mt-4 block text-xs">
              <span className="flex justify-between">{label}<output dir="ltr" className="text-white/60">{shown}</output></span>
              <input type="range" aria-label={label} min={min} max={max} step={step} value={settings[name]} disabled={lite && name === 'blur'} onChange={(e) => update({ [name]: Number(e.target.value) })} className="mt-2 w-full accent-sky-300" />
            </label>
          ))}
          <label className="mt-4 flex items-center justify-between text-xs">{t('dashboard.glassSettings.tint')}<input type="color" aria-label={t('dashboard.glassSettings.tint')} value={settings.tint} onChange={(e) => update({ tint: e.target.value })} className="h-7 w-11 cursor-pointer rounded border border-white/20 bg-transparent p-0.5" /></label>
          <button type="button" disabled={!custom} onClick={reset} className="mt-5 w-full rounded-lg border border-white/20 py-2 text-xs hover:bg-white/15 disabled:opacity-40">{t('dashboard.glassSettings.reset')}</button>
        </aside>
      )}
    </>
  )
}
