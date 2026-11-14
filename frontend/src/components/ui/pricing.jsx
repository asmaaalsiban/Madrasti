import confetti from 'canvas-confetti'
import NumberFlow from '@number-flow/react'
import { motion } from 'framer-motion'
import { Check, Star } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { InteractiveHoverButton } from './interactive-hover-button'

// Pricing plans block (a shadcn "pricing" component adapted to this project: JavaScript, react-router, no shadcn/radix; RTL aware).
// plans: [{ name, price, yearlyPrice, period, features[], description, buttonText, href, isPopular }]; prices are numbers or numeric strings.
// labels: { monthly, annual, save, popular, billedMonthly, billedAnnually } so every text comes from the caller's i18n.
const useDesktop = () => {
  const query = '(min-width: 768px)'
  const [on, setOn] = useState(() => Boolean(window.matchMedia?.(query).matches))
  useEffect(() => {
    const m = window.matchMedia?.(query)
    if (!m) return undefined
    const f = () => setOn(m.matches)
    m.addEventListener('change', f)
    return () => m.removeEventListener('change', f)
  }, [])
  return on
}

export function Pricing({ plans, title, description, labels, currency = 'JOD', locale }) {
  const [annual, setAnnual] = useState(false)
  const desktop = useDesktop()
  const toggle = useRef(null)
  const rtl = typeof document !== 'undefined' && document.documentElement.dir === 'rtl'

  const flip = () => {
    const next = !annual
    setAnnual(next)
    if (next && toggle.current) {
      const r = toggle.current.getBoundingClientRect()
      confetti({ particleCount: 50, spread: 60, origin: { x: (r.left + r.width / 2) / window.innerWidth, y: (r.top + r.height / 2) / window.innerHeight }, colors: ['#006ddb', '#13426f', '#ffd166', '#06d6a0'], ticks: 200, gravity: 1.2, decay: 0.94, startVelocity: 30, shapes: ['circle'] })
    }
  }

  return (
    <div className="py-12">
      <div className="mb-10 space-y-3 text-center">
        <h2 className="text-3xl font-bold tracking-tight sm:text-4xl">{title}</h2>
        {description && <p className="whitespace-pre-line text-lg text-slate-500">{description}</p>}
      </div>

      <div className="mb-10 flex items-center justify-center gap-3 font-semibold">
        <span className={annual ? 'text-slate-400' : ''}>{labels.monthly}</span>
        <button ref={toggle} type="button" role="switch" aria-checked={annual} aria-label={labels.annual} onClick={flip} className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${annual ? 'bg-brand' : 'bg-slate-300'}`}>
          <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${annual ? 'start-[1.375rem]' : 'start-0.5'}`} />
        </button>
        <span className={annual ? '' : 'text-slate-400'}>{labels.annual} <span className="text-brand">({labels.save})</span></span>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        {plans.map((plan, index) => (
          <motion.div
            key={plan.name}
            initial={{ y: 50, opacity: 1 }}
            whileInView={desktop ? { y: plan.isPopular ? -20 : 0, opacity: 1, x: (index === 2 ? -30 : index === 0 ? 30 : 0) * (rtl ? -1 : 1), scale: index === 0 || index === 2 ? 0.94 : 1 } : {}}
            viewport={{ once: true }}
            transition={{ duration: 1.6, type: 'spring', stiffness: 100, damping: 30, delay: 0.4, opacity: { duration: 0.5 } }}
            className={`relative flex flex-col rounded-2xl bg-white p-6 text-center lg:justify-center ${plan.isPopular ? 'z-10 border-2 border-brand' : 'z-0 mt-5 border'}`}
          >
            {plan.isPopular && (
              <div className="absolute end-0 top-0 flex items-center rounded-es-xl rounded-se-xl bg-brand px-2 py-0.5 text-white">
                <Star className="h-4 w-4 fill-current" aria-hidden />
                <span className="ms-1 font-semibold">{labels.popular}</span>
              </div>
            )}
            <div className="flex flex-1 flex-col">
              <p className="text-base font-semibold text-slate-500">{plan.name}</p>
              <div className="mt-6 flex items-center justify-center gap-x-2" dir="ltr">
                <span className="text-5xl font-bold tracking-tight text-deep-harbor">
                  <NumberFlow value={Number(annual ? plan.yearlyPrice : plan.price)} locales={locale} format={{ style: 'currency', currency, minimumFractionDigits: 0, maximumFractionDigits: 0 }} transformTiming={{ duration: 500, easing: 'ease-out' }} willChange />
                </span>
                {plan.period && <span className="text-sm font-semibold tracking-wide text-slate-500">/ {plan.period}</span>}
              </div>
              <p className="text-xs leading-5 text-slate-500">{annual ? labels.billedAnnually : labels.billedMonthly}</p>

              <ul className="mt-5 flex flex-col gap-2">
                {plan.features.map((feature) => (
                  <li key={feature} className="flex items-start gap-2">
                    <Check className="mt-1 h-4 w-4 shrink-0 text-brand" aria-hidden />
                    <span className="text-start">{feature}</span>
                  </li>
                ))}
              </ul>

              <hr className="my-4 w-full" />

              <InteractiveHoverButton as={Link} to={plan.href} variant={plan.isPopular ? undefined : 'outline'} className="w-full px-4 py-2 text-lg">{plan.buttonText}</InteractiveHoverButton>
              <p className="mt-6 text-xs leading-5 text-slate-500">{plan.description}</p>
            </div>
          </motion.div>
        ))}
      </div>
    </div>
  )
}
export default Pricing
