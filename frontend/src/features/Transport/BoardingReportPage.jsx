import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { DateInput } from '../../components/shared/DateInput'

const today = () => new Date().toISOString().slice(0, 10)

// The admin's view of the boarding ticks of a day: every bus and round, how many boarded, who did not, and what was not ticked.
export default function BoardingReportPage() {
  const { t, i18n } = useTranslation()
  const [date, setDate] = useState(today())
  const { data } = useQuery({ queryKey: ['transport', 'boarding-report', date], queryFn: async () => (await api.get('/transport/boarding/report', { params: { date } })).data.data })
  const name = (x) => x[`name_${i18n.language}`] || x.name_ar
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('transport.report.title')}</h1>
        <Link to="/admin/transport" className="text-sm text-brand underline">{t('transport.report.back')}</Link>
      </div>
      <label className="block text-sm">{t('transport.report.date')}<DateInput className="mt-1 block rounded border p-1.5" value={date} onChange={(e) => setDate(e.target.value)} /></label>
      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : data.rows.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('transport.report.none')}</p> : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="w-full text-start text-sm">
            <thead className="bg-slate-50"><tr>{['bus', 'round', 'boarded', 'not_boarded', 'not_ticked', 'missed'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`transport.report.cols.${k}`)}</th>)}</tr></thead>
            <tbody>
              {data.rows.map((r) => (
                <tr key={`${r.bus.id}-${r.round.id}`} className="border-t align-top">
                  <td className="p-2"><b>{t('transport.busNo', { number: r.bus.number })}</b>{r.bus.escort && <span className="block text-xs text-slate-500">{r.bus.escort}</span>}</td>
                  <td className="p-2">{t(`transport.periods.${r.round.period}`)} · {name(r.round)}</td>
                  <td className="p-2 text-green-700">{r.boarded} / {r.total}</td>
                  <td className={`p-2 ${r.not_boarded > 0 ? 'font-bold text-red-700' : ''}`}>{r.not_boarded}</td>
                  <td className={`p-2 ${r.not_ticked > 0 ? 'text-amber-700' : ''}`}>{r.not_ticked}</td>
                  <td className="p-2">{r.missed.map(name).join('، ') || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
