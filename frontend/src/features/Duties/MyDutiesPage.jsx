import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ShieldCheck } from 'lucide-react'
import { api } from '../../api/client'

const dayLabel = (iso, language) => new Date(`${iso}T12:00:00`).toLocaleDateString(language === 'ar' ? 'ar-JO-u-nu-latn' : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long' })

function DutyRow({ duty, language, soon }) {
  return (
    <li className={`flex flex-wrap items-center justify-between gap-2 rounded-lg border p-4 ${soon ? 'bg-deep-harbor text-white' : 'bg-snow'}`}>
      <span className="flex items-center gap-3">
        <ShieldCheck aria-hidden size={22} className={soon ? 'text-sky-tint' : 'text-deep-harbor'} />
        <span><b className="block">{duty.location}</b><span className={`text-sm ${soon ? 'text-white/80' : 'text-slate-600'}`}>{dayLabel(duty.date, language)}</span></span>
      </span>
      <span dir="ltr" className="text-sm font-bold">{duty.starts_at} – {duty.ends_at}</span>
    </li>
  )
}

// A teacher's own supervision duties: what is coming first (the next one stands out), then the last month.
export default function MyDutiesPage() {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useQuery({ queryKey: ['duties', 'me'], queryFn: async () => (await api.get('/duties/me')).data.data })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  return (
    <section className="space-y-6">
      <h1>{t('duties.myTitle')}</h1>
      <div className="space-y-3">
        <h2 className="text-lg">{t('duties.upcoming')}</h2>
        {data.upcoming.length === 0 ? <p className="rounded-lg border bg-snow p-6 text-center text-slate-600">{t('duties.noneUpcoming')}</p> : (
          <ul className="space-y-2">{data.upcoming.map((d, i) => <DutyRow key={d.id} duty={d} language={i18n.language} soon={i === 0} />)}</ul>
        )}
      </div>
      {data.past.length > 0 && (
        <div className="space-y-3">
          <h2 className="text-lg">{t('duties.past')}</h2>
          <ul className="space-y-2">{data.past.map((d) => <DutyRow key={d.id} duty={d} language={i18n.language} />)}</ul>
        </div>
      )}
      <p className="text-sm text-slate-500">{t('duties.total', { count: data.total })}</p>
    </section>
  )
}
