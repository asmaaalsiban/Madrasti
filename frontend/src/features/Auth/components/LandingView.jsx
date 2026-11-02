import { useTranslation } from 'react-i18next'
import { Award, BookOpenCheck, CalendarClock, CircleCheck, FileBarChart, GraduationCap, Heart, MessageCircle, Briefcase, Users, Wallet, Star, Sparkles } from 'lucide-react'

const YEAR = new Date().getFullYear()
const ROLES = [['admin', Briefcase], ['teacher', Users], ['student', GraduationCap], ['parent', Heart]]
const FEATURES = [['grades', Award], ['attendance', CircleCheck], ['schedule', CalendarClock], ['chat', MessageCircle], ['reports', FileBarChart], ['finance', Wallet]]
// Chips floating around the hero: what the school does for you.
const CHIPS = [
  ['grades', Award, 'start-[6%] top-[22%]', '0s'],
  ['attendance', CircleCheck, 'end-[7%] top-[18%]', '0.8s'],
  ['schedule', CalendarClock, 'start-[10%] bottom-[16%]', '1.6s'],
  ['chat', MessageCircle, 'end-[9%] bottom-[22%]', '2.4s'],
]

function Cloud({ className }) {
  return (
    <svg aria-hidden viewBox="0 0 120 50" className={`absolute fill-white ${className}`}>
      <path d="M25 46a16 16 0 0 1-1-32 22 22 0 0 1 42-4 18 18 0 0 1 30 14 14 14 0 0 1-3 22z" />
    </svg>
  )
}

// A small school building, drawn in the palette: it stands at the bottom of the hero.
function SchoolIllustration() {
  return (
    <svg aria-hidden viewBox="0 0 320 150" className="mx-auto mt-10 w-full max-w-xl">
      <ellipse cx="160" cy="142" rx="150" ry="8" fill="#13426f" opacity="0.12" />
      <rect x="40" y="62" width="240" height="78" rx="10" fill="#13426f" />
      <path d="M30 66 160 14l130 52z" fill="#0254a5" />
      <rect x="140" y="8" width="40" height="0" fill="none" />
      <rect x="146" y="30" width="28" height="22" rx="4" fill="#bde1f9" />
      <path d="M160 14V2" stroke="#13426f" strokeWidth="3" strokeLinecap="round" />
      <path d="M160 2h16l-5 5 5 5h-16z" fill="#2e96ff" />
      <rect x="140" y="92" width="40" height="48" rx="6" fill="#2e96ff" />
      <circle cx="170" cy="118" r="2.5" fill="#fff" />
      {[62, 100, 204, 242].map((x) => <rect key={x} x={x} y="82" width="22" height="26" rx="5" fill="#bde1f9" />)}
      {[62, 100, 204, 242].map((x) => <rect key={`b${x}`} x={x} y="116" width="22" height="16" rx="4" fill="#cde7fb" />)}
      <circle cx="22" cy="118" r="16" fill="#50a7ff" opacity="0.55" />
      <rect x="20" y="124" width="4" height="16" fill="#13426f" opacity="0.5" />
      <circle cx="300" cy="120" r="14" fill="#73b9ff" opacity="0.6" />
      <rect x="298" y="126" width="4" height="14" fill="#13426f" opacity="0.5" />
    </svg>
  )
}

// The welcome page itself: used for a school (its logo and name) and for the platform when no school is chosen yet.
// `header` and `actions` are the buttons at the top and under the title; `ctaActions` those of the closing band.
export default function LandingView({ name, logo, title, text, header, actions, ctaActions }) {
  const { t } = useTranslation()
  return (
    <div className="min-h-screen overflow-x-hidden">
      <header className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-4">
        <div className="flex items-center gap-2">
          {logo ? <img src={logo} alt="" className="h-10 w-10 rounded-2xl object-cover" /> : <span aria-hidden className="grid h-10 w-10 place-items-center rounded-2xl bg-deep-harbor font-extrabold text-white">{name.trim().charAt(0)}</span>}
          <span className="text-lg font-extrabold text-deep-harbor">{name}</span>
        </div>
        <div className="flex items-center gap-2">{header}</div>
      </header>
      <section className="relative isolate overflow-hidden bg-sky-wash">
        <Cloud className="-z-10 start-[-2%] top-8 w-40 opacity-90 motion-safe:animate-[drift_14s_ease-in-out_infinite]" />
        <Cloud className="-z-10 end-[4%] top-24 w-28 opacity-80 motion-safe:animate-[drift_18s_ease-in-out_infinite_reverse]" />
        <Cloud className="-z-10 start-[30%] top-3 hidden w-24 opacity-70 sm:block" />
        <Star aria-hidden className="absolute start-[18%] top-[12%] -z-10 hidden fill-white text-white sm:block" size={22} />
        <Sparkles aria-hidden className="absolute end-[22%] top-[10%] -z-10 hidden text-brand sm:block" size={26} />
        <Star aria-hidden className="absolute end-[15%] top-[48%] -z-10 hidden fill-sky-tint text-sky-tint lg:block" size={18} />

        {CHIPS.map(([key, Icon, pos, delay]) => (
          <span key={key} aria-hidden style={{ animationDelay: delay }} className={`absolute ${pos} hidden items-center gap-2 rounded-full bg-snow px-4 py-2 text-sm font-bold text-deep-harbor shadow motion-safe:animate-[float_5s_ease-in-out_infinite] lg:flex`}>
            <span className="grid h-7 w-7 place-items-center rounded-full bg-sky-tint"><Icon size={15} /></span>
            {t(`landing.features.${key}`)}
          </span>
        ))}

        <div className="mx-auto max-w-3xl px-4 pb-0 pt-14 text-center sm:pt-20">
          <div className="mx-auto mb-5 grid h-28 w-28 place-items-center rounded-full bg-snow p-2 shadow ring-8 ring-white/50">
            {logo ? <img src={logo} alt="" className="h-full w-full rounded-full object-cover" /> : <GraduationCap aria-hidden size={52} className="text-deep-harbor" />}
          </div>
          <span className="inline-block rounded-full bg-sky-tint px-5 py-2 text-sm font-bold text-deep-harbor">{t('landing.welcome')}</span>
          <h1 className="mt-4 text-5xl font-extrabold leading-tight sm:text-7xl">{name}</h1>
          <p className="mt-3 text-xl font-bold text-deep-harbor">{title}</p>
          <p className="mx-auto mt-3 max-w-2xl text-ink sm:text-lg">{text}</p>
          <div className="mt-8 flex flex-wrap justify-center gap-3">{actions}</div>
          <SchoolIllustration />
        </div>
      </section>

      <main>
        <section className="mx-auto max-w-6xl px-4 py-16">
          <h2 className="mb-8 text-center text-3xl font-extrabold">{t('landing.rolesTitle')}</h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {ROLES.map(([role, Icon]) => (
              <div key={role} className="feature-card">
                <span aria-hidden className="mb-4 grid h-12 w-12 place-items-center rounded-2xl bg-white/15"><Icon size={24} /></span>
                <h3>{t(`roles.${role}`)}</h3>
                <p className="hint mt-2">{t(`landing.roles.${role}`)}</p>
              </div>
            ))}
          </div>
        </section>

        <section className="bg-sky-wash/60 py-16">
          <div className="mx-auto max-w-6xl px-4">
            <h2 className="mb-8 text-center text-3xl font-extrabold">{t('landing.featuresTitle')}</h2>
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
              {FEATURES.map(([key, Icon]) => (
                <div key={key} className="flex items-center gap-3 rounded-lg bg-snow p-4">
                  <span aria-hidden className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-sky-tint text-deep-harbor"><Icon size={22} /></span>
                  <span className="font-bold text-deep-harbor">{t(`landing.features.${key}`)}</span>
                </div>
              ))}
            </div>
          </div>
        </section>

        <section className="mx-auto max-w-6xl px-4 py-16">
          <div className="rounded-[32px] bg-deep-harbor px-6 py-12 text-center text-white">
            <BookOpenCheck aria-hidden size={36} className="mx-auto mb-3 text-sky-tint" />
            <h2 className="text-3xl font-extrabold !text-white">{t('landing.ctaTitle', { name })}</h2>
            <p className="mx-auto mt-2 max-w-xl text-white/80">{t('landing.ctaText')}</p>
            <div className="mt-6 flex flex-wrap justify-center gap-3">{ctaActions}</div>
          </div>
        </section>
      </main>

      <footer className="border-t py-6 text-center text-sm text-slate-500">
        {t('landing.footer', { year: YEAR, name })}
      </footer>
    </div>
  )
}
