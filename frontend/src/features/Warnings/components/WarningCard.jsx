import { useTranslation } from 'react-i18next'

const LEVEL = { 1: 'border-sky-300 bg-sky-50', 2: 'border-amber-300 bg-amber-50', 3: 'border-orange-400 bg-orange-50', 4: 'border-red-400 bg-red-50' }
const BADGE = { 1: 'bg-sky-600', 2: 'bg-amber-600', 3: 'bg-orange-600', 4: 'bg-red-700' }

// One warning: its level and category, the title and the details, who wrote it and when, and whether it was seen.
export default function WarningCard({ w, who = null, children = null }) {
  const { t } = useTranslation()
  return (
    <article className={`space-y-2 rounded-xl border p-3 ${w.status === 'withdrawn' ? 'border-slate-200 bg-slate-50 opacity-70' : LEVEL[w.level]}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <span className={`rounded-full px-2 py-0.5 text-xs font-bold text-white ${BADGE[w.level]}`}>{t(`warnings.levels.${w.level}`)}</span>
          <span className="rounded-full bg-white/70 px-2 py-0.5 text-xs">{t(`warnings.categories.${w.category}`)}</span>
          {w.status !== 'active' && <span className="rounded-full bg-slate-200 px-2 py-0.5 text-xs">{t(`warnings.status.${w.status}`)}</span>}
          {w.kind === 'rule' && <span className="rounded-full bg-white/70 px-2 py-0.5 text-xs">{t('warnings.byRule')}</span>}
        </div>
        <span dir="ltr" className="text-xs text-slate-600">{w.issued_on}</span>
      </div>
      {who && <p className="text-sm font-bold">{who}</p>}
      <h3 className="font-bold">{w.title}</h3>
      {w.body && <p dir="auto" className="whitespace-pre-line text-sm">{w.body}</p>}
      <p className="text-xs text-slate-600">
        {w.issued_by && <>{t('warnings.by', { name: w.issued_by })}</>}
        {w.acknowledged_at && <> · {t('warnings.seenOn', { date: w.acknowledged_at.slice(0, 10) })}</>}
        {w.withdrawn_reason && <> · {t('warnings.withdrawnBecause', { reason: w.withdrawn_reason })}</>}
      </p>
      {children}
    </article>
  )
}
