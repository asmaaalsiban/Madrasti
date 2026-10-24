import { useTranslation } from 'react-i18next'

const COLORS = { paid: 'bg-green-100 text-green-800', partial: 'bg-amber-100 text-amber-800', pending: 'bg-slate-100 text-slate-700', overdue: 'bg-red-100 text-red-700' }

export default function StatusBadge({ status }) {
  const { t } = useTranslation()
  return <span className={`rounded-full px-2 py-0.5 text-xs ${COLORS[status]}`}>{t(`finance.status.${status}`)}</span>
}
