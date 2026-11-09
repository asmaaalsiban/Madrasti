// The glass pieces of the principal's overview: a frosted card that shows empty glass first and sharpens its content in, a
// segmented half-circle gauge, pill-shaped columns with the best one lit, and a stat pill. They live on the dark stage (white text).

import { AnimatePresence, motion, useReducedMotion } from 'framer-motion'
import { CountUp, Reveal, useGrown } from '../../../components/shared/motion'

/** A frosted card. Until `ready`, only the empty glass (with a soft shimmer) shows; then the content sharpens in out of a blur. */
export function GlassCard({ i = 0, ready = true, className = '', dark = false, children }) {
  const still = useReducedMotion()
  return (
    <Reveal i={i} className={`h-full ${className}`}>
      <motion.section whileHover={still ? undefined : { y: -4 }} className={`${dark ? 'glass-dark' : 'glass-card'} relative h-full overflow-hidden rounded-3xl p-5`}>
        {!ready && <span aria-hidden className="glass-shimmer absolute inset-0" />}
        <motion.div initial={still ? false : { opacity: 0, filter: 'blur(12px)' }} animate={ready ? { opacity: 1, filter: 'blur(0px)' } : { opacity: 0, filter: 'blur(12px)' }} transition={{ duration: 0.6, ease: 'easeOut' }} className="relative h-full">
          {children}
        </motion.div>
      </motion.section>
    </Reveal>
  )
}

/** Title row of a card. */
export const CardTitle = ({ children, right }) => (
  <div className="mb-3 flex items-center justify-between gap-2"><h2 className="text-sm font-bold text-white/80">{children}</h2>{right}</div>
)

/** A number with a round icon and a small caption (the three pills at the top right of the stage). */
export function StatPill({ icon: Icon, value, suffix = '', label, i = 0 }) {
  return (
    <Reveal i={i} className="flex items-center gap-3 rounded-full bg-white/10 py-2 ps-2 pe-5 backdrop-blur">
      <span aria-hidden className="grid h-10 w-10 place-items-center rounded-full bg-white/15 text-sky-100"><Icon size={18} /></span>
      <span>
        <b className="block text-lg leading-tight text-white" dir="ltr"><CountUp value={value} suffix={typeof value === 'number' ? suffix : ''} /></b>
        <span className="block text-xs text-white/60">{label}</span>
      </span>
    </Reveal>
  )
}

/** A half-circle gauge made of segments that light up one after another; the big number counts up in the middle. */
export function SegmentGauge({ percent, label, caption }) {
  const grown = useGrown()
  const p = Math.max(0, Math.min(100, Number(percent) || 0))
  const N = 30
  const lit = Math.round((p / 100) * N)
  return (
    <div className="relative mx-auto w-full max-w-60">
      <svg viewBox="0 0 200 112" className="w-full" role="img" aria-label={`${label}: ${Math.round(p)}%`}>
        <defs>
          <linearGradient id="gauge-lit" x1="0" x2="1"><stop offset="0" stopColor="var(--color-brand)" /><stop offset="1" stopColor="var(--color-sky-tint)" /></linearGradient>
        </defs>
        {Array.from({ length: N }, (_, k) => {
          const a = Math.PI - (k / (N - 1)) * Math.PI
          const x1 = 100 + 70 * Math.cos(a); const y1 = 104 - 70 * Math.sin(a)
          const x2 = 100 + 92 * Math.cos(a); const y2 = 104 - 92 * Math.sin(a)
          const on = grown && k < lit
          return <line key={k} x1={x1} y1={y1} x2={x2} y2={y2} strokeWidth="6" strokeLinecap="round" stroke={on ? 'url(#gauge-lit)' : 'rgba(255,255,255,.16)'} style={{ transition: `stroke .25s ease ${k * 28}ms` }} />
        })}
      </svg>
      <div className="absolute inset-x-0 bottom-1 text-center">
        <b className="text-4xl font-extrabold text-white" dir="ltr"><CountUp value={Math.round(p)} suffix="%" /></b>
        {caption && <p className="mt-0.5 text-xs text-white/60">{caption}</p>}
      </div>
    </div>
  )
}

/** Pill-shaped columns on a dark glass: the biggest is lit (with its value in a chip), the others stay soft; a dashed line marks the average. */
export function PillColumns({ items, label, format = (v) => v, avgLabel, maxValue }) {
  const grown = useGrown()
  const max = maxValue ?? Math.max(...items.map((x) => x.value), 1)
  const top0 = Math.max(...items.map((x) => x.value))
  const avg = items.length ? items.reduce((s, x) => s + x.value, 0) / items.length : 0
  const top = items.findIndex((x) => x.value === top0)
  return (
    <div role="img" aria-label={label} className="relative">
      <div className="relative flex h-60 items-end gap-2 sm:gap-3">
        {avg > 0 && (
          <div aria-hidden className="pointer-events-none absolute inset-x-0 border-t border-dashed border-white/30" style={{ bottom: `calc(${(avg / max) * 100}% * 0.86 + 1.5rem)` }}>
            <span className="absolute -top-3 start-0 rounded-full bg-white/15 px-2 py-0.5 text-[10px] text-white/80" dir="ltr">{avgLabel} {format(Math.round(avg * 10) / 10)}</span>
          </div>
        )}
        {items.map((x, n) => {
          const best = n === top && x.value > 0
          return (
            <div key={x.label} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1.5" title={`${x.label}: ${format(x.value)}`}>
              <div className="relative flex w-full flex-1 items-end justify-center">
                {best && <motion.span initial={{ opacity: 0, y: 6 }} animate={grown ? { opacity: 1, y: 0 } : {}} transition={{ delay: 0.9 + n * 0.07 }} className="absolute -top-1 z-10 -translate-y-full whitespace-nowrap rounded-full bg-white px-2 py-0.5 text-[10px] font-bold text-deep-harbor" dir="ltr">{format(x.value)}</motion.span>}
                <div className="w-full max-w-9 rounded-full" style={{
                  height: grown ? `${Math.max((x.value / max) * 100, x.value > 0 ? 8 : 3)}%` : '0%',
                  background: best ? 'linear-gradient(180deg, var(--color-sky-tint), var(--color-brand))' : 'rgba(255,255,255,.16)',
                  boxShadow: best ? '0 0 22px color-mix(in srgb, var(--color-sky-tint) 55%, transparent)' : 'none',
                  transition: `height .9s cubic-bezier(.2,.8,.2,1) ${n * 70}ms`,
                }} />
              </div>
              <span className="w-full truncate text-center text-[10px] text-white/60" dir="ltr">{x.label}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

/** Cross-fades its content (blur out, blur in) when `id` changes: used for the tabs of the analytics card. */
export function BlurSwap({ id, children }) {
  const still = useReducedMotion()
  if (still) return <div key={id}>{children}</div>
  return (
    <AnimatePresence mode="wait" initial={false}>
      <motion.div key={id} initial={{ opacity: 0, filter: 'blur(10px)', y: 8 }} animate={{ opacity: 1, filter: 'blur(0px)', y: 0 }} exit={{ opacity: 0, filter: 'blur(10px)', y: -8 }} transition={{ duration: 0.28 }}>
        {children}
      </motion.div>
    </AnimatePresence>
  )
}
