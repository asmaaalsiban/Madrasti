import { animate, motion, useReducedMotion } from 'framer-motion'
import { useEffect, useState } from 'react'

// Shared motion for the dashboards: a card that fades and rises into place, a number that counts up, and a flag for "grow from zero" charts.
// Everything stands still (and shows its final state at once) for people who asked for less motion.
// Only opacity and position move (no blur: a blur filter on a large area is repainted every frame and makes a slow computer stutter),
// and quickly: what the person came for (menus, titles) is `instant` and never waits for an animation.

/** A block that fades and rises into place; `i` staggers a row of them. `instant` draws it at once, with no motion at all. */
export function Reveal({ i = 0, className = '', children, instant = false, ...rest }) {
  const still = useReducedMotion()
  if (still || instant) return <div className={className} {...rest}>{children}</div>
  return (
    <motion.div
      className={className}
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, delay: Math.min(i, 8) * 0.03, ease: 'easeOut' }}
      {...rest}
    >
      {children}
    </motion.div>
  )
}

/** A number that counts up from 0 to `value` (non-numbers are shown as they are). */
export function CountUp({ value, format = (v) => Math.round(v).toLocaleString(), suffix = '' }) {
  const still = useReducedMotion()
  const target = typeof value === 'number' && Number.isFinite(value) ? value : null
  const [shown, setShown] = useState(still ? target : 0)
  useEffect(() => {
    if (target === null || still) { setShown(target); return undefined }
    const controls = animate(0, target, { duration: 0.6, ease: 'easeOut', onUpdate: setShown })
    return () => controls.stop()
  }, [target, still])
  if (target === null) return <>{value}</>
  return <>{format(shown ?? 0)}{suffix}</>
}

/** False on the first paint and true just after, so a chart can grow from zero with a CSS transition. */
export function useGrown() {
  const still = useReducedMotion()
  const [grown, setGrown] = useState(Boolean(still))
  useEffect(() => {
    if (still) return undefined
    const id = setTimeout(() => setGrown(true), 60)
    return () => clearTimeout(id)
  }, [still])
  return grown || Boolean(still)
}

/** Wraps a page: on every change of page (give it `key={pathname}`) the content fades in (quickly, no blur). */
export function PageEnter({ children }) {
  const still = useReducedMotion()
  if (still) return <>{children}</>
  return <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ duration: 0.15, ease: 'easeOut' }}>{children}</motion.div>
}

/** Pill tabs with a pill that slides from one tab to the next (`layoutId` must be unique per group). */
export function PillTabs({ tabs, value, onChange, layoutId, label, className = '' }) {
  const still = useReducedMotion()
  return (
    <div role="tablist" aria-label={label} className={`flex w-full gap-0.5 rounded-full bg-white/10 p-1 ${className}`}>
      {tabs.map(([key, text]) => {
        const on = key === value
        return (
          <button key={key} type="button" role="tab" aria-selected={on} onClick={() => onChange(key)} className={`relative min-w-0 flex-1 truncate rounded-full px-1 py-1.5 text-[11px] transition-colors sm:text-xs ${on ? 'font-bold text-deep-harbor' : 'text-white/70 hover:text-white'}`}>
            {on && (still ? <span className="absolute inset-0 rounded-full bg-sky-tint" /> : <motion.span layoutId={layoutId} transition={{ type: 'spring', stiffness: 420, damping: 34 }} className="absolute inset-0 rounded-full bg-sky-tint" />)}
            <span className="relative">{text}</span>
          </button>
        )
      })}
    </div>
  )
}
