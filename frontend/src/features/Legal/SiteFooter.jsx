import { GraduationCap, Mail, MapPin, Phone } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { company } from '../../config/company'
import { getSchool } from '../../tenant/school'
import { useRegistration } from '../Central/useRegistration'

const YEAR = new Date().getFullYear()

// The full footer of the public pages: who the platform is, then three short columns of links, then the copyright line.
// Only real things are shown: the contact column lists the company details that are set (VITE_COMPANY_*), and is left out when none is.
export default function SiteFooter() {
  const { t } = useTranslation()
  const owner = company.legalName || company.name
  const { enabled: canRegister } = useRegistration()
  const platform = getSchool()
    ? [['/', t('footer.home')], ['/login', t('landing.login')]]
    : [['/', t('footer.home')], ...(canRegister ? [['/register', t('footer.register')]] : []), ['/find-school', t('footer.findSchool')]]
  const legal = [['/privacy', t('legal.privacy.title')], ['/terms', t('legal.terms.title')], ['/cookies', t('legal.cookies.title')]]
  const hasContact = Boolean(company.email || company.phone || company.address)
  const linkClass = 'text-slate-600 transition-colors hover:text-deep-harbor hover:underline'

  return (
    <footer className="border-t border-fog bg-white px-4 py-12 text-slate-700 print:hidden sm:px-8">
      <div className="mx-auto max-w-6xl">
        <div className={`grid gap-10 ${hasContact ? 'lg:grid-cols-[1.6fr_repeat(3,1fr)]' : 'lg:grid-cols-[1.6fr_repeat(2,1fr)]'}`}>
          <div className="space-y-4">
            <p className="flex items-center gap-3 text-2xl font-extrabold text-deep-harbor">
              <span aria-hidden className="grid h-10 w-10 place-items-center rounded-xl bg-brand text-white"><GraduationCap size={22} /></span>
              {company.name}
            </p>
            <p className="max-w-xs text-slate-600">{t('footer.tagline')}</p>
            {(company.email || company.phone) && (
              <p className="flex items-center gap-3">
                {company.email && <a href={`mailto:${company.email}`} aria-label={company.email} className="grid h-9 w-9 place-items-center rounded-full border border-fog text-slate-600 transition-colors hover:bg-snow hover:text-deep-harbor"><Mail size={18} aria-hidden /></a>}
                {company.phone && <a href={`tel:${company.phone}`} aria-label={company.phone} className="grid h-9 w-9 place-items-center rounded-full border border-fog text-slate-600 transition-colors hover:bg-snow hover:text-deep-harbor"><Phone size={18} aria-hidden /></a>}
              </p>
            )}
          </div>

          <nav aria-label={t('footer.platform')}>
            <h2 className="mb-4 text-lg font-bold text-deep-harbor">{t('footer.platform')}</h2>
            <ul className="space-y-3">{platform.map(([to, text]) => <li key={to}><Link to={to} className={linkClass}>{text}</Link></li>)}</ul>
          </nav>

          <nav aria-label={t('legal.docs')}>
            <h2 className="mb-4 text-lg font-bold text-deep-harbor">{t('footer.legal')}</h2>
            <ul className="space-y-3">{legal.map(([to, text]) => <li key={to}><Link to={to} className={linkClass}>{text}</Link></li>)}</ul>
          </nav>

          {hasContact && (
            <div>
              <h2 className="mb-4 text-lg font-bold text-deep-harbor">{t('footer.contact')}</h2>
              <ul className="space-y-3">
                {company.email && <li className="flex items-center gap-2"><Mail size={16} aria-hidden className="shrink-0 text-slate-400" /><a href={`mailto:${company.email}`} dir="ltr" className={linkClass}>{company.email}</a></li>}
                {company.phone && <li className="flex items-center gap-2"><Phone size={16} aria-hidden className="shrink-0 text-slate-400" /><a href={`tel:${company.phone}`} dir="ltr" className={linkClass}>{company.phone}</a></li>}
                {company.address && <li className="flex items-start gap-2"><MapPin size={16} aria-hidden className="mt-1 shrink-0 text-slate-400" /><span>{company.address}</span></li>}
              </ul>
            </div>
          )}
        </div>

        <div className="mt-10 flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t border-fog pt-6 text-sm text-slate-600">
          <span>{t('footer.rights', { year: YEAR, name: owner })}</span>
          <span className="flex gap-4">
            <Link to="/terms" className={linkClass}>{t('legal.terms.title')}</Link>
            <Link to="/privacy" className={linkClass}>{t('legal.privacy.title')}</Link>
          </span>
        </div>
      </div>
    </footer>
  )
}
