import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import TimetableGrid from '../components/TimetableGrid'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// The periods a teacher cannot teach in: click a period to mark it; the timetable generator and hand edits keep away from them.
export default function TeacherAvailabilityPage() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [teacherId, setTeacherId] = useState('')
  const teachers = useQuery({ queryKey: ['availability', 'teachers'], queryFn: async () => (await api.get('/staff', { params: { per_page: 100, category: 'teaching' } })).data.data })
  const active = teacherId || teachers.data?.[0]?.id
  const periods = useQuery({ queryKey: ['availability', 'periods'], queryFn: async () => (await api.get('/periods')).data.data })
  const saved = useQuery({ queryKey: ['availability', active], queryFn: async () => (await api.get(`/teachers/${active}/unavailability`)).data.data, enabled: Boolean(active) })
  const list = periods.data?.periods ?? periods.data ?? []

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('availability.title')}</h1>
      <p className="text-sm text-slate-600">{t('availability.help')}</p>
      <label className="block text-sm">
        {t('timetable.teacher')}
        <select className="mt-1 block rounded border p-2" value={active ?? ''} onChange={(e) => setTeacherId(e.target.value)}>
          {(teachers.data ?? []).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
        </select>
      </label>
      {saved.isSuccess && <Editor key={active} teacherId={active} initial={saved.data.period_ids} periods={list} onSaved={() => client.invalidateQueries({ queryKey: ['availability', active] })} />}
    </section>
  )
}

// Keyed by the teacher: the picked set starts from what is saved and the result of a save stays visible.
function Editor({ teacherId, initial, periods, onSaved }) {
  const { t } = useTranslation()
  const [picked, setPicked] = useState(new Set(initial))
  const [result, setResult] = useState(null)

  const save = useMutation({
    mutationFn: () => api.put(`/teachers/${teacherId}/unavailability`, { period_ids: [...picked] }).then((r) => r.data.data),
    onSuccess: (data) => { setResult(data); onSaved() },
  })
  const toggle = (period) => setPicked((prev) => { const next = new Set(prev); if (next.has(period.id)) next.delete(period.id); else next.add(period.id); return next })
  return (
    <div className="space-y-4">
      {periods.length === 0 ? <p className="rounded bg-white p-4 text-slate-600">{t('timetable.noPeriods')}</p> : (
        <TimetableGrid periods={periods} entries={[]} marked={picked} onCellClick={toggle} />
      )}
      {result?.clashes?.length > 0 && (
        <div role="status" className="rounded bg-amber-50 p-3 text-sm text-amber-800">
          <p>{t('availability.clashes')}</p>
          <ul className="list-disc ps-5">{result.clashes.map((c) => <li key={c.id}>{c.classroom}</li>)}</ul>
        </div>
      )}
      {result && result.clashes.length === 0 && <p role="status" className="text-sm text-green-700">{t('availability.saved')}</p>}
      <InteractiveHoverButton type="button" className="px-4 py-1.5 disabled:opacity-50" disabled={save.isPending} onClick={() => save.mutate()}>{t('admin.actions.save')}</InteractiveHoverButton>
    </div>
  )
}
