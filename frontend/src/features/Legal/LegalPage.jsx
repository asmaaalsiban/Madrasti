import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { company } from '../../config/company'
import LanguageSwitcher from '../../components/shared/LanguageSwitcher'

const DOCS = ['privacy', 'terms', 'cookies']

// A legal page (privacy policy, terms of use, cookies notice): the text lives in the translations (legal.<doc>.sections).
export default function LegalPage({ doc }) {
  const { t } = useTranslation()
  const sections = t(`legal.${doc}.sections`, { returnObjects: true, company: company.name })
  const details = [[t('legal.company.name'), company.legalName || company.name], [t('legal.company.registration'), company.registration], [t('legal.company.address'), company.address], [t('legal.company.email'), company.privacyEmail], [t('legal.company.phone'), company.phone]].filter(([, v]) => v)
  return (
    <main className="mx-auto max-w-3xl space-y-6 p-4 sm:p-8">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Link to="/" className="text-sm text-brand underline">{t('legal.home')}</Link>
        <LanguageSwitcher />
      </div>
      <h1>{t(`legal.${doc}.title`)}</h1>
      <p className="text-sm text-slate-600">{t('legal.updated', { date: t(`legal.${doc}.updated`) })}</p>
      {company.draft && <p role="note" className="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">{t('legal.draft')}</p>}
      {Array.isArray(sections) && sections.map((s) => (
        <section key={s.h} className="space-y-2">
          <h2 className="text-lg">{s.h}</h2>
          {(s.p ?? []).map((p, i) => <p key={i} className="leading-relaxed">{p}</p>)}
          {s.list && <ul className="list-disc space-y-1 ps-6">{s.list.map((x, i) => <li key={i}>{x}</li>)}</ul>}
        </section>
      ))}
      <section className="space-y-1 rounded-lg border bg-snow p-4">
        <h2 className="text-lg">{t('legal.company.title')}</h2>
        <dl className="space-y-1 text-sm">
          {details.map(([k, v]) => <div key={k} className="flex flex-wrap gap-2"><dt className="font-bold">{k}:</dt><dd><bdi>{v}</bdi></dd></div>)}
        </dl>
      </section>
      <nav aria-label={t('legal.docs')} className="flex flex-wrap gap-4 border-t pt-4 text-sm">
        {DOCS.filter((d) => d !== doc).map((d) => <Link key={d} to={`/${d}`} className="text-brand underline">{t(`legal.${d}.title`)}</Link>)}
      </nav>
    </main>
  )
}
