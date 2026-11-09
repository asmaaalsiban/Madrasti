import { motion, useReducedMotion } from 'framer-motion'
import { ArrowUpRight } from 'lucide-react'
import { CountUp } from './motion'

// A glass stat card for the dashboards: title, a big value, an optional change in % (shown only when it can be computed) and an icon.
// Lifts a little on hover (not for people who asked for less motion).
export default function StatsCard({ title, value, change, icon: Icon, suffix = '', className = '' }) {
  const still = useReducedMotion()
  return (
    <motion.div whileHover={still ? undefined : { y: -5 }} className={`glass-card rounded-2xl p-6 ${className}`}>
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-white/60">{title}</p>
          <h2 className="text-2xl font-bold text-white"><CountUp value={value} suffix={suffix} /></h2>
          {change !== undefined && change !== null && (
            <p className="flex items-center gap-1 text-sm text-emerald-400">
              <ArrowUpRight size={16} aria-hidden className={change < 0 ? 'rotate-90 text-red-300' : ''} />
              <span dir="ltr" className={change < 0 ? 'text-red-300' : ''}>{change > 0 ? '+' : ''}{change}%</span>
            </p>
          )}
        </div>
        {Icon && <span aria-hidden className="grid h-10 w-10 place-items-center rounded-xl bg-white/12 text-sky-100"><Icon size={20} /></span>}
      </div>
    </motion.div>
  )
}
