import { animate, motion, useReducedMotion } from 'framer-motion'
import { useEffect, useState } from 'react'

// Shared motion for the dashboards: a card that fades in out of a blur, a number that counts up, and a flag for "grow from zero" charts.
// Everything stands still (and shows its final state at once) for people who asked for less motion.

/** A block that sharpens and rises into place; `i` staggers a row of them. */
export function Reveal({ i = 0, className = '', children, ...rest }) {
  const still = useReducedMotion()
  if (still) return <div className={className} {...rest}>{children}</div>
  return (
    <motion.div
      className={className}
      initial={{ opacity: 0, y: 18, filter: 'blur(10px)' }}
      animate={{ opacity: 1, y: 0, filter: 'blur(0px)' }}
      transition={{ duration: 0.5, delay: Math.min(i, 12) * 0.06, ease: 'easeOut' }}
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
    const controls = animate(0, target, { duration: 0.9, ease: 'easeOut', onUpdate: setShown })
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

/** Wraps a page: on every change of page (give it `key={pathname}`) the content sharpens out of a light blur. */
export function PageEnter({ children }) {
  const still = useReducedMotion()
  if (still) return <>{children}</>
  return <motion.div initial={{ opacity: 0, filter: 'blur(8px)' }} animate={{ opacity: 1, filter: 'blur(0px)' }} transition={{ duration: 0.35, ease: 'easeOut' }}>{children}</motion.div>
}
