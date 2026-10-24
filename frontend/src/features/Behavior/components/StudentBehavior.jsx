import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

const TABS = ['weeks', 'months', 'terms']

// A student's behaviour results: weekly, monthly and term tables (each built from the one before), remarks and quick notes.
// canEdit: staff can delete their notes; the page that shows this also offers adding one.
export default function StudentBehavior({ studentId, canEdit = false }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [tab, setTab] = useState('weeks')
  const key = ['behavior', 'student', studentId]
  const { data, isLoading } = useQuery({ queryKey: key, queryFn: async () => (await api.get(`/students/${studentId}/behavior`)).data.data })
  const removeNote = useMutation({ mutationFn: (id) => api.delete(`/behavior/notes/${id}`), onSuccess: () => client.invalidateQueries({ queryKey: key }) })
  const name = (c) => c[`name_${i18n.language}`] || c.name_ar

  if (isLoading) return <p>{t('common.loading')}</p>
  const used = data.criteria.filter((c) => data[tab].some((r) => r.criteria[c.id] !== undefined))
  const rows = data[tab]
  const label = (r) => (tab === 'weeks' ? t('behavior.weekOf', { date: r.week_starts_on }) : tab === 'months' ? t('behavior.monthRow', { month: r.month, weeks: r.weeks }) : t('behavior.termRow', { name: r.term.name, months: r.months }))

  return (
    <div className="space-y-4">
      <div role="tablist" className="flex gap-2">
        {TABS.map((k) => (
          <button key={k} type="button" role="tab" aria-selected={tab === k} className={`rounded-full border px-3 py-1 text-sm ${tab === k ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => setTab(k)}>{t(`behavior.tabs.${k}`)}</button>
        ))}
      </div>
      <p className="text-xs text-slate-500">{t(`behavior.builtFrom.${tab}`)}</p>

      {rows.length === 0 ? <p className="rounded bg-white p-4 text-slate-600">{t('behavior.noResults')}</p> : (
        <div className="overflow-x-auto rounded-lg bg-white shadow-sm">
          <table className="w-full text-start text-sm">
            <thead className="bg-slate-100">
              <tr><th className="p-2 text-start" />{used.map((c) => <th key={c.id} className="p-2 text-center font-medium">{name(c)}</th>)}<th className="p-2 text-center font-bold">{t('behavior.overall')}</th></tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={label(r)} className="border-t">
                  <td className="p-2">{label(r)}</td>
                  {used.map((c) => <td key={c.id} className="p-2 text-center">{r.criteria[c.id] ?? '—'}</td>)}
                  <td className="p-2 text-center font-bold">{r.overall}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data.remarks.length > 0 && (
        <div className="space-y-2">
          <h3 className="font-bold">{t('behavior.remarks')}</h3>
          <ul className="space-y-2">
            {data.remarks.map((r) => (
              <li key={r.id} className="rounded border bg-white p-3 text-sm">
                <p className="whitespace-pre-line">{r.note}</p>
                <p className="text-xs text-slate-500">{r.evaluated_on} · {r.teacher}</p>
              </li>
            ))}
          </ul>
        </div>
      )}

      {data.notes.length > 0 && (
        <div className="space-y-2">
          <h3 className="font-bold">{t('behavior.notes')}</h3>
          <ul className="space-y-2">
            {data.notes.map((n) => (
              <li key={n.id} className={`rounded border p-3 text-sm ${n.type === 'positive' ? 'border-green-300 bg-green-50' : 'border-red-300 bg-red-50'}`}>
                <p><span className="font-medium">{t(`behavior.type.${n.type}`)}:</span> {n.note}</p>
                <p className="text-xs text-slate-500">{n.noted_on} · {n.teacher}{canEdit && !n.visible_to_parent ? ` · ${t('behavior.hiddenFromParent')}` : ''}</p>
                {canEdit && <button type="button" className="text-xs text-red-700 underline" onClick={() => removeNote.mutate(n.id)}>{t('admin.actions.delete')}</button>}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
