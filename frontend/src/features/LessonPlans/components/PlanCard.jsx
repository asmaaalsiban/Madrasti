import { useTranslation } from 'react-i18next'

const COLORS = { draft: 'bg-slate-100 text-slate-700', submitted: 'bg-amber-100 text-amber-800', approved: 'bg-green-100 text-green-800', rejected: 'bg-red-100 text-red-700' }

// One weekly plan: title, section and subject, week, objectives and content. status (teacher / admin only) shows a badge.
export default function PlanCard({ plan, children }) {
  const { t, i18n } = useTranslation()
  const subject = plan.subject[`name_${i18n.language}`] || plan.subject.name_ar
  return (
    <li className="space-y-1 rounded-lg border bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-bold">{plan.title}</span>
        {plan.status && <span className={`rounded-full px-2 py-0.5 text-xs ${COLORS[plan.status]}`}>{t(`lessonPlans.status.${plan.status}`)}</span>}
      </div>
      <p className="text-sm text-slate-600">{plan.classroom.name} · {subject} · {t('behavior.weekOf', { date: plan.week_starts_on })} · {plan.teacher.name}</p>
      {plan.objectives && <p className="whitespace-pre-line text-sm"><span className="font-medium">{t('lessonPlans.objectives')}:</span> {plan.objectives}</p>}
      {plan.content && <p className="whitespace-pre-line text-sm text-slate-700">{plan.content}</p>}
      {plan.status === 'rejected' && plan.review_note && <p role="note" className="rounded bg-red-50 p-2 text-sm text-red-700">{t('lessonPlans.rejectedWith', { note: plan.review_note })}</p>}
      {children}
    </li>
  )
}
