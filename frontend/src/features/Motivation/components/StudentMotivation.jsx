import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// One student's points, badges, the next badge to aim for and the latest awards (staff can undo their own).
export default function StudentMotivation({ studentId, canEdit = false }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const name = (x) => x[`name_${i18n.language}`] || x.name_ar
  const { data, isLoading } = useQuery({ queryKey: ['motivation', 'student', String(studentId)], queryFn: async () => (await api.get(`/students/${studentId}/motivation`)).data.data })
  const undo = useMutation({ mutationFn: (id) => api.delete(`/motivation/awards/${id}`), onSuccess: () => client.invalidateQueries({ queryKey: ['motivation'] }) })
  if (isLoading || !data) return null
  const sign = (n) => (n > 0 ? `+${n}` : String(n))
  const left = data.next_badge ? data.next_badge.points_required - data.total : 0

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-4 rounded-lg border bg-white p-4">
        <div><p className="text-sm text-slate-600">{t('motivation.totalPoints')}</p><p className="text-3xl font-bold" dir="ltr">{data.total}</p></div>
        <div><p className="text-sm text-slate-600">{t('motivation.weekPoints')}</p><p className="text-xl font-bold" dir="ltr">{sign(data.week)}</p></div>
        {data.is_star && <span className="rounded-full bg-yellow-100 px-3 py-1 text-yellow-800">⭐ {t('motivation.starThisWeek')}</span>}
      </div>
      {data.next_badge && (
        <p className="rounded bg-slate-50 p-3 text-sm">{t('motivation.nextBadge', { icon: data.next_badge.icon, badge: name(data.next_badge), count: left })}</p>
      )}
      <div>
        <h2 className="mb-2 font-bold">{t('motivation.badges')}</h2>
        {data.badges.length === 0 ? <p className="text-slate-600">{t('motivation.noBadges')}</p> : (
          <ul className="flex flex-wrap gap-2">
            {data.badges.map((b) => <li key={b.id} className="rounded-lg border bg-white px-3 py-2"><span className="text-2xl">{b.icon}</span> {name(b)}</li>)}
          </ul>
        )}
      </div>
      <div>
        <h2 className="mb-2 font-bold">{t('motivation.history')}</h2>
        {data.awards.length === 0 ? <p className="text-slate-600">{t('motivation.noAwards')}</p> : (
          <ul className="space-y-1">
            {data.awards.map((a) => (
              <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 rounded border bg-white p-2 text-sm">
                <span><b dir="ltr" className={a.points > 0 ? 'text-green-700' : 'text-red-700'}>{sign(a.points)}</b> {a.note} <span className="text-slate-500">· {a.teacher} · {a.awarded_on}</span></span>
                {canEdit && a.mine && <button type="button" className="rounded border border-red-300 px-2 py-0.5 text-red-700" onClick={() => undo.mutate(a.id)}>{t('motivation.undo')}</button>}
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  )
}
