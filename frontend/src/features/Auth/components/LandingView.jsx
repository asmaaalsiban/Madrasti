import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Award, BookOpenCheck, CalendarClock, CircleCheck, FileBarChart, GraduationCap, Heart, MessageCircle, Briefcase, Users, Wallet } from 'lucide-react'

const YEAR = new Date().getFullYear()
// Kids reading among books under the stars (MotionSites free media shelf, #24). Swap it for the school's own film; if it cannot load, the aurora below stays.
const HERO_VIDEO = 'https://d8j0ntlcm91z4.cloudfront.net/user_38xzZboKViGWJOttwIXH07lWA1P/hf_20260314_131748_f2ca2a28-fed7-44c8-b9a9-bd9acdd5ec31.mp4'
const ROLES = [['admin', Briefcase], ['teacher', Users], ['student', GraduationCap], ['parent', Heart]]
const FEATURES = [['grades', Award], ['attendance', CircleCheck], ['schedule', CalendarClock], ['chat', MessageCircle], ['reports', FileBarChart], ['finance', Wallet]]
const BARS = [46, 68, 54, 82, 74, 92, 64]
const LESSONS = [['08:00', 'w-[78%]'], ['09:00', 'w-[56%]'], ['10:15', 'w-[88%]']]

// A looping background film that fades in once it can play. Not used when the person asks for less motion or for less data.
function HeroVideo({ src }) {
  const [ready, setReady] = useState(false)
  const skip = typeof window !== 'undefined' && (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches || navigator.connection?.saveData)
  if (skip) return null
  return <video aria-hidden autoPlay muted loop playsInline preload="auto" src={src} onLoadedData={() => setReady(true)} onError={() => setReady(false)} className={`lp-video ${ready ? 'is-ready' : ''}`} />
}

// Shows its children when they scroll into view (at once for people who ask for less motion, or when the browser cannot tell).
function Reveal({ children, className = '', delay = 0 }) {
  const ref = useRef(null)
  const [seen, setSeen] = useState(false)
  useEffect(() => {
    const el = ref.current
    if (!el || typeof IntersectionObserver === 'undefined' || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
      setSeen(true)
      return undefined
    }
    const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { setSeen(true); io.disconnect() } }, { threshold: 0.15 })
    io.observe(el)
    return () => io.disconnect()
  }, [])
  return <div ref={ref} style={{ '--d': `${delay}ms` }} className={`lp-reveal ${seen ? 'is-in' : ''} ${className}`}>{children}</div>
}

// A soft light that follows the pointer over a card.
function spotlight(e) {
  const r = e.currentTarget.getBoundingClientRect()
  e.currentTarget.style.setProperty('--mx', `${e.clientX - r.left}px`)
  e.currentTarget.style.setProperty('--my', `${e.clientY - r.top}px`)
}

// The school day as a glass dashboard: decorative (it only shows the idea), so screen readers skip it.
function DashboardMock({ t }) {
  const ref = useRef(null)
  const tilt = (e) => {
    const el = ref.current
    if (!el || e.pointerType === 'touch') return
    const r = el.getBoundingClientRect()
    el.style.setProperty('--ry', `${(((e.clientX - r.left) / r.width) - 0.5) * 8}deg`)
    el.style.setProperty('--rx', `${(0.5 - ((e.clientY - r.top) / r.height)) * 8}deg`)
  }
  const rest = () => { ref.current?.style.setProperty('--rx', '0deg'); ref.current?.style.setProperty('--ry', '0deg') }
  return (
    <div aria-hidden className="lp-stage" onPointerMove={tilt} onPointerLeave={rest}>
      <div ref={ref} className="lp-tilt">
        <div className="lp-glass lp-mock">
          <div className="flex items-center justify-between">
            <span className="text-sm font-bold text-white/90">{t('landing.features.attendance')}</span>
            <span className="rounded-full bg-emerald-400/20 px-3 py-0.5 text-xs font-bold text-emerald-200">{t('attendance.present')}</span>
          </div>
          <div className="mt-4 flex items-center gap-5">
            <svg viewBox="0 0 100 100" className="h-24 w-24 shrink-0 -rotate-90">
              <circle cx="50" cy="50" r="40" fill="none" stroke="rgba(255,255,255,.14)" strokeWidth="11" />
              <circle cx="50" cy="50" r="40" fill="none" stroke="#7ee0b0" strokeWidth="11" strokeLinecap="round" strokeDasharray="251.3" className="lp-ring" />
            </svg>
            <div dir="ltr" className="text-white">
              <p className="text-4xl font-extrabold leading-none">96%</p>
              <p className="mt-1 text-xs text-white/60">1 · 2 · 3 · 4 · 5</p>
            </div>
          </div>
          <div dir="ltr" className="mt-5 flex h-20 items-end gap-2">
            {BARS.map((h, i) => <span key={i} style={{ height: `${h}%`, '--i': i }} className="lp-bar flex-1 rounded-t-md bg-gradient-to-t from-sky-300/30 to-sky-200" />)}
          </div>
        </div>

        <div className="lp-glass lp-mock-side">
          <div className="flex items-center gap-2 text-white">
            <CalendarClock size={16} className="text-sky-200" /><span className="text-sm font-bold">{t('landing.features.schedule')}</span>
          </div>
          <div dir="ltr" className="mt-3 space-y-2">
            {LESSONS.map(([time, w]) => (
              <div key={time} className="flex items-center gap-2 text-[11px] text-white/70">
                <span className="w-9 shrink-0">{time}</span><span className={`h-2 rounded-full bg-white/30 ${w}`} />
              </div>
            ))}
          </div>
        </div>

        <div className="lp-glass lp-mock-chat">
          <span className="grid h-9 w-9 place-items-center rounded-full bg-sky-200 text-deep-harbor"><MessageCircle size={17} /></span>
          <div className="space-y-1.5"><span className="block h-2 w-24 rounded-full bg-white/60" /><span className="block h-2 w-14 rounded-full bg-white/30" /></div>
        </div>

        <div className="lp-glass lp-mock-grade">
          <Award size={18} className="text-amber-200" />
          <span dir="ltr" className="text-2xl font-extrabold text-white">18.5</span>
        </div>
      </div>
    </div>
  )
}

// The welcome page itself: used for a school (its logo and name) and for the platform when no school is chosen yet.
// `header` and `actions` are the buttons at the top and under the title; `ctaActions` those of the closing band.
export default function LandingView({ name, logo, title, text, header, actions, ctaActions }) {
  const { t } = useTranslation()
  const words = name.trim().split(/\s+/)
  const marquee = [...FEATURES, ...FEATURES]
  return (
    <div className="min-h-screen overflow-x-hidden">
      <section className="lp-hero lp-hero-first">
        <div aria-hidden className="lp-aurora"><i /><i /><i /></div>
        <HeroVideo src={HERO_VIDEO} />
        <div aria-hidden className="lp-veil" />
        <div aria-hidden className="lp-grid" />
        <div aria-hidden className="lp-grain" />

        <header className="relative z-10 mx-auto flex w-full max-w-6xl items-center justify-between gap-3 px-4 py-4">
          <div className="flex items-center gap-2">
            {logo ? <img src={logo} alt="" className="h-10 w-10 rounded-2xl object-cover" /> : <span aria-hidden className="grid h-10 w-10 place-items-center rounded-2xl bg-white/15 font-extrabold text-white ring-1 ring-white/25">{name.trim().charAt(0)}</span>}
            <span className="text-lg font-extrabold text-white">{name}</span>
          </div>
          <div className="flex items-center gap-2 rounded-full bg-white/90 p-1 ps-3 text-deep-harbor shadow-lg backdrop-blur">{header}</div>
        </header>

        <main id="main" tabIndex={-1} className="outline-none">
          <div className="lp-hero-body relative z-10 mx-auto flex w-full max-w-6xl flex-col justify-end px-4 pb-12 pt-24 sm:pb-16">
            <span className="lp-rise inline-flex w-fit items-center gap-2 rounded-full bg-white/10 px-4 py-1.5 text-sm font-bold text-sky-100 ring-1 ring-white/20 backdrop-blur" style={{ '--i': 0 }}>
              <span aria-hidden className="lp-pulse h-2 w-2 rounded-full bg-emerald-300" />{t('landing.welcome')}
            </span>
            <h1 dir="auto" className="lp-title mt-5 max-w-4xl text-start text-white">
              {words.map((w, i) => <span key={i}>{i > 0 && ' '}<span className="lp-word" style={{ '--i': i + 1 }}>{w}</span></span>)}
            </h1>
            <p className="lp-rise mt-5 max-w-2xl text-xl font-bold text-sky-100 sm:text-2xl" style={{ '--i': words.length + 2 }}>{title}</p>
            <p className="lp-rise mt-3 max-w-xl text-white/70 sm:text-lg" style={{ '--i': words.length + 3 }}>{text}</p>
            <div className="lp-rise mt-8 flex flex-wrap gap-3" style={{ '--i': words.length + 4 }}>{actions}</div>
          </div>

          <div aria-hidden className="lp-marquee relative z-10">
            <div className="lp-marquee-track">
              {marquee.map(([key, Icon], i) => (
                <span key={`${key}${i}`} className="inline-flex items-center gap-2 whitespace-nowrap px-6 text-sm font-bold text-white/80">
                  <Icon size={16} className="text-sky-200" />{t(`landing.features.${key}`)}<span className="ms-6 text-white/25">✦</span>
                </span>
              ))}
            </div>
          </div>
        </main>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-20">
        <Reveal><h2 className="mb-10 text-center text-3xl font-extrabold sm:text-4xl">{t('landing.rolesTitle')}</h2></Reveal>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {ROLES.map(([role, Icon], i) => (
            <Reveal key={role} delay={i * 90}>
              <div className="lp-card h-full" onPointerMove={spotlight}>
                <span aria-hidden className="mb-5 grid h-12 w-12 place-items-center rounded-2xl bg-white/12 ring-1 ring-white/20"><Icon size={24} /></span>
                <h3>{t(`roles.${role}`)}</h3>
                <p className="mt-2 text-sm text-white/75">{t(`landing.roles.${role}`)}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </section>

      <section className="lp-hero py-20">
        <div aria-hidden className="lp-aurora"><i /><i /><i /></div>
        <div aria-hidden className="lp-grid" />
        <div aria-hidden className="lp-grain" />
        <div className="relative z-10 mx-auto grid max-w-6xl items-center gap-12 px-4 lg:grid-cols-2">
          <div>
            <Reveal><h2 className="text-3xl font-extrabold !text-white sm:text-5xl">{t('landing.featuresTitle')}</h2></Reveal>
            <div className="mt-8 grid grid-cols-2 gap-3">
              {FEATURES.map(([key, Icon], i) => (
                <Reveal key={key} delay={i * 70}>
                  <div className="lp-glass lp-tile-glass" onPointerMove={spotlight}>
                    <span aria-hidden className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white/12 text-sky-100"><Icon size={20} /></span>
                    <span className="font-bold text-white">{t(`landing.features.${key}`)}</span>
                  </div>
                </Reveal>
              ))}
            </div>
          </div>
          <Reveal delay={150}><DashboardMock t={t} /></Reveal>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-20">
        <Reveal>
          <div className="lp-hero rounded-[36px] px-6 py-16 text-center">
            <div aria-hidden className="lp-aurora"><i /><i /><i /></div>
            <div aria-hidden className="lp-grain" />
            <div className="relative z-10">
              <BookOpenCheck aria-hidden size={38} className="mx-auto mb-4 text-sky-200" />
              <h2 className="text-3xl font-extrabold !text-white sm:text-5xl">{t('landing.ctaTitle', { name })}</h2>
              <p className="mx-auto mt-3 max-w-xl text-white/80">{t('landing.ctaText')}</p>
              <div className="mt-8 flex flex-wrap justify-center gap-3">{ctaActions}</div>
            </div>
          </div>
        </Reveal>
      </section>

      <footer className="border-t py-6 text-center text-sm text-slate-500">
        {t('landing.footer', { year: YEAR, name })}
      </footer>
    </div>
  )
}
