import { useTranslation } from 'react-i18next'

const COLORS = { absent: 'text-red-700', late: 'text-yellow-700', present: 'text-green-700' }

// A list of days with their status (dates stay left-to-right in Arabic).
export default function StatusDates({ records }) {
  const { t } = useTranslation()
  if (records.length === 0) return <p className="text-sm text-slate-500">{t('attendance.noDays')}</p>
  return (
    <ul className="max-h-64 divide-y overflow-y-auto text-sm">
      {records.map((r) => (
        <li key={r.date} className="flex justify-between gap-3 py-1.5">
          <span dir="ltr">{r.date}</span>
          <span className={COLORS[r.status]}>{t(`attendance.${r.status}`)}</span>
        </li>
      ))}
    </ul>
  )
}
