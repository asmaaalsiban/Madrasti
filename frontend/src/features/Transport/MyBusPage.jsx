import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Bus, Check, MapPin, Phone, X } from 'lucide-react'
import { api } from '../../api/client'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

const Call = ({ phone }) => (phone ? <a href={`tel:${phone}`} dir="ltr" className="inline-flex items-center gap-1 font-semibold text-brand"><Phone aria-hidden size={13} />{phone}</a> : null)

// The driver's (or the escort's) own page: the bus, who is on it with him, and for each round the students to pick up or take home
// with their section and a parent to call.
export default function MyBusPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['transport', 'mine'], queryFn: async () => (await api.get('/transport/mine')).data.data })
  const boarding = useQuery({ queryKey: ['transport', 'boarding'], queryFn: async () => (await api.get('/transport/boarding/mine')).data.data })
  const date = boarding.data?.today ?? new Date().toISOString().slice(0, 10)
  const marks = boarding.data?.marks ?? []
  const statusOf = (busId, roundId, studentId) => marks.find((m) => m.bus_id === busId && m.round_id === roundId && m.student_id === studentId)?.status ?? null
  const setMarks = (fn) => client.setQueryData(['transport', 'boarding'], (old) => old && { ...old, marks: fn(old.marks) })
  // Ticking is for the school's own record: nobody is told. Pressing the same button again clears the tick.
  const tick = useMutation({
    mutationFn: ({ busId, roundId, studentId, status }) => api.put('/transport/boarding', { date, bus_id: busId, round_id: roundId, student_id: studentId, status }),
    onMutate: ({ busId, roundId, studentId, status }) => setMarks((all) => [...all.filter((m) => !(m.bus_id === busId && m.round_id === roundId && m.student_id === studentId)), ...(status ? [{ bus_id: busId, round_id: roundId, student_id: studentId, status }] : [])]),
    onError: () => client.invalidateQueries({ queryKey: ['transport', 'boarding'] }),
  })
  const all = useMutation({
    mutationFn: ({ busId, roundId }) => api.put('/transport/boarding/all', { date, bus_id: busId, round_id: roundId }),
    onSettled: () => client.invalidateQueries({ queryKey: ['transport', 'boarding'] }),
  })
  const nameOf = (s) => s[`name_${i18n.language}`] || s.name_ar
  if (isLoading) return <p className="text-slate-500">{t('common.loading')}</p>
  return (
    <section className="space-y-5">
      <h1 className="text-xl font-bold">{t('transport.myBusTitle')}</h1>
      {(data ?? []).length === 0 && <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('transport.noBusYet')}</p>}
      {(data ?? []).map((b) => (
        <article key={b.id} className="space-y-4 rounded-lg border bg-white p-4">
          <header className="space-y-1">
            <h2 className="flex items-center gap-2 text-lg font-extrabold"><Bus aria-hidden size={20} />{t('transport.busNo', { number: b.number })}
              <span className="rounded-full bg-sky-tint px-2 py-0.5 text-xs font-bold text-deep-harbor">{t(`transport.part.${b.my_part}`)}</span></h2>
            <p className="flex items-center gap-2 text-sm text-slate-600"><MapPin aria-hidden size={14} />{t('transport.areaFromTo', { from: b.area_from, to: b.area_to })}</p>
            <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
              {b.driver && <p>{t('transport.driver')}: <bdi className="font-medium">{b.driver.name}</bdi> <Call phone={b.driver.phone} /></p>}
            </div>
            {b.notes && <p className="text-sm text-slate-500">{b.notes}</p>}
          </header>
          {b.rounds.length === 0 && <p className="text-sm text-slate-500">{t('transport.noRoundsYet')}</p>}
          {b.rounds.map((r) => (
            <div key={r.id} className="space-y-2">
              <h3 className="text-sm font-bold text-deep-harbor">
                {t(`transport.periods.${r.period}`)} · {r[`name_${i18n.language}`] || r.name_ar}{r.starts_at && <> · <bdi dir="ltr">{r.starts_at}{r.ends_at ? ` – ${r.ends_at}` : ''}</bdi></>}
                <span className="ms-2 font-normal text-slate-500">({t('transport.studentsCount', { count: r.students.length })})</span>
                {r.escort && <span className="ms-2 font-normal text-slate-600">· {t('transport.escort')}: <bdi className="font-medium">{r.escort.name}</bdi> <Call phone={r.escort.phone} /></span>}
              </h3>
              {r.students.length > 0 && (
                <p className="flex flex-wrap items-center gap-3 text-sm">
                  <span role="status">{t('transport.boarding.summary', { boarded: r.students.filter((x) => statusOf(b.id, r.id, x.id) === 'boarded').length, missed: r.students.filter((x) => statusOf(b.id, r.id, x.id) === 'not_boarded').length, left: r.students.filter((x) => statusOf(b.id, r.id, x.id) === null).length })}</span>
                  <InteractiveHoverButton variant="outline" type="button" disabled={all.isPending} className="px-3 py-1 text-brand" aria-label={`${t('transport.boarding.everyone')} ${r.name_en || r.name_ar}`} onClick={() => all.mutate({ busId: b.id, roundId: r.id })}>{t('transport.boarding.everyone')}</InteractiveHoverButton>
                  <span className="text-xs text-slate-500">{t('transport.boarding.private')}</span>
                </p>
              )}
              {r.students.length === 0 ? <p className="text-sm text-slate-500">{t('transport.noStudentsInRound')}</p> : (
                <div className="overflow-x-auto rounded-lg border">
                  <table className="w-full text-start text-sm">
                    <thead className="bg-slate-50"><tr>{['student', 'classroom', 'parent', 'boarding'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`transport.mine.${k}`)}</th>)}</tr></thead>
                    <tbody>
                      {r.students.map((s) => (
                        <tr key={s.id} className="border-t">
                          <td className="p-2 font-medium"><bdi>{nameOf(s)}</bdi></td>
                          <td className="p-2">{s.classroom ?? '—'}</td>
                          <td className="p-2">{s.parent_name ? <><bdi>{s.parent_name}</bdi> <Call phone={s.parent_phone} /></> : '—'}</td>
                          <td className="p-2">
                            <div role="group" aria-label={`${nameOf(s)} ${t('transport.mine.boarding')}`} className="flex gap-1.5">
                              {[['boarded', Check, 'bg-green-600 text-white border-green-600'], ['not_boarded', X, 'bg-red-600 text-white border-red-600']].map(([status, Icon, on]) => {
                                const current = statusOf(b.id, r.id, s.id)
                                return (
                                  <button key={status} type="button" aria-pressed={current === status} aria-label={`${nameOf(s)}: ${t(`transport.boarding.${status}`)}`}
                                    className={`inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs ${current === status ? on : 'bg-white text-slate-700'}`}
                                    onClick={() => tick.mutate({ busId: b.id, roundId: r.id, studentId: s.id, status: current === status ? null : status })}>
                                    <Icon aria-hidden size={13} />{t(`transport.boarding.${status}`)}
                                  </button>
                                )
                              })}
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          ))}
        </article>
      ))}
    </section>
  )
}
