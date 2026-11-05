import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Bus, Pencil, Plus, Trash2, Users } from 'lucide-react'
import { api } from '../../api/client'
import ConfirmDialog from '../../components/shared/ConfirmDialog'
import Modal from '../../components/shared/Modal'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const nameOf = (o, lang) => o?.[`name_${lang}`] || o?.name_ar || ''
const PERIODS = ['morning', 'evening']

function RoundDialog({ round, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ name_ar: round?.name_ar ?? '', name_en: round?.name_en ?? '', period: round?.period ?? 'morning', starts_at: round?.starts_at ?? '', ends_at: round?.ends_at ?? '' })
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => { const body = { ...form, starts_at: form.starts_at || null, ends_at: form.ends_at || null }; return round ? api.put(`/transport/rounds/${round.id}`, body) : api.post('/transport/rounds', body) },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['transport'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  return (
    <Modal open title={round ? t('transport.editRound') : t('transport.newRound')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('transport.period')}
          <select className="mt-1 w-full rounded border p-2" value={form.period} onChange={set('period')}>{['morning', 'evening'].map((p) => <option key={p} value={p}>{t(`transport.periods.${p}`)}</option>)}</select>
        </label>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{t('transport.nameAr')}<input required className="mt-1 w-full rounded border p-2" placeholder="الجولة الأولى" value={form.name_ar} onChange={set('name_ar')} /></label>
          <label className="block text-sm">{t('transport.nameEn')}<input required dir="ltr" className="mt-1 w-full rounded border p-2" placeholder="First round" value={form.name_en} onChange={set('name_en')} /></label>
          <label className="block text-sm">{t('transport.startsAt')}<input type="time" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.starts_at} onChange={set('starts_at')} /></label>
          <label className="block text-sm">{t('transport.endsAt')}<input type="time" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.ends_at} onChange={set('ends_at')} /></label>
        </div>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-brand px-5 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

function BusDialog({ bus, rounds, drivers = [], escorts = [], onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  // The driver is chosen from the staff (position "driver"); "other" lets the principal type a name for a driver who is not registered.
  const [driverChoice, setDriverChoice] = useState(bus?.driver_id ? String(bus.driver_id) : bus || drivers.length === 0 ? 'other' : '')
  const [escortId, setEscortId] = useState(bus?.escort_id ? String(bus.escort_id) : '')
  const [form, setForm] = useState({ number: bus?.number ?? '', driver_name: bus?.driver_name ?? '', driver_phone: bus?.driver_phone ?? '', capacity: bus?.capacity ?? 30, area_from: bus?.area_from ?? '', area_to: bus?.area_to ?? '', notes: bus?.notes ?? '', is_active: bus?.is_active ?? true, round_ids: bus?.round_ids ?? [] })
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => { const staff = driverChoice !== 'other'; const body = { ...form, driver_id: staff ? Number(driverChoice) : null, escort_id: escortId ? Number(escortId) : null, driver_name: staff ? undefined : form.driver_name, driver_phone: staff ? undefined : (form.driver_phone || null), notes: form.notes || null }; return bus ? api.put(`/transport/buses/${bus.id}`, body) : api.post('/transport/buses', body) },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['transport'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const toggle = (id) => setForm({ ...form, round_ids: form.round_ids.includes(id) ? form.round_ids.filter((r) => r !== id) : [...form.round_ids, id] })
  return (
    <Modal open size="lg" title={bus ? t('transport.editBus') : t('transport.newBus')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <div className="grid gap-3 sm:grid-cols-3">
          <label className="block text-sm">{t('transport.busNumber')}<input required dir="ltr" className="mt-1 w-full rounded border p-2" value={form.number} onChange={set('number')} /></label>
          <label className="block text-sm">{t('transport.driver')}
            <select required className="mt-1 w-full rounded border p-2" value={driverChoice} onChange={(e) => setDriverChoice(e.target.value)}>
              <option value="" disabled>{t('transport.chooseDriver')}</option>
              {drivers.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
              <option value="other">{t('transport.driverOther')}</option>
            </select>
          </label>
          {driverChoice === 'other' && <>
            <label className="block text-sm">{t('transport.driverName')}<input required className="mt-1 w-full rounded border p-2" placeholder={t('transport.driverHint')} value={form.driver_name} onChange={set('driver_name')} /></label>
            <label className="block text-sm">{t('transport.driverPhone')}<input dir="ltr" className="mt-1 w-full rounded border p-2" value={form.driver_phone} onChange={set('driver_phone')} /></label>
          </>}
          <label className="block text-sm">{t('transport.escort')}
            <select className="mt-1 w-full rounded border p-2" value={escortId} onChange={(e) => setEscortId(e.target.value)}>
              <option value="">{t('transport.escortNone')}</option>
              {escorts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </label>
          <label className="block text-sm">{t('transport.areaFrom')}<input required className="mt-1 w-full rounded border p-2" value={form.area_from} onChange={set('area_from')} /></label>
          <label className="block text-sm">{t('transport.areaTo')}<input required className="mt-1 w-full rounded border p-2" value={form.area_to} onChange={set('area_to')} /></label>
          <label className="block text-sm">{t('transport.capacity')}<input required type="number" min="1" max="100" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.capacity} onChange={set('capacity')} /></label>
        </div>
        <fieldset className="space-y-2 text-sm">
          <legend className="mb-1">{t('transport.servesRounds')}</legend>
          {rounds.length === 0 && <span className="text-slate-500">{t('transport.addRoundsFirst')}</span>}
          {PERIODS.map((p) => rounds.some((r) => r.period === p) && (
            <div key={p} className="flex flex-wrap items-center gap-2">
              <span className="w-20 text-slate-500">{t(`transport.periods.${p}`)}</span>
              {rounds.filter((r) => r.period === p).map((r) => (
                <label key={r.id} className={`flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1 ${form.round_ids.includes(r.id) ? 'border-brand bg-sky-tint font-bold' : ''}`}>
                  <input type="checkbox" className="sr-only" checked={form.round_ids.includes(r.id)} onChange={() => toggle(r.id)} />{nameOf(r, i18n.language)}
                </label>
              ))}
            </div>
          ))}
        </fieldset>
        <label className="block text-sm">{t('transport.notes')}<input className="mt-1 w-full rounded border p-2" value={form.notes} onChange={set('notes')} /></label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />{t('transport.active')}</label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-brand px-5 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

function RidersDialog({ bus, rounds, onClose }) {
  const { t, i18n } = useTranslation()
  const { data } = useQuery({ queryKey: ['transport', 'riders', bus.id], queryFn: async () => (await api.get(`/transport/buses/${bus.id}/riders`)).data.data })
  return (
    <Modal open size="lg" title={t('transport.ridersOf', { number: bus.number })} onClose={onClose}>
      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : data.length === 0 ? <p className="text-slate-500">{t('transport.noRiders')}</p> : (
        <div className="space-y-4">
          {rounds.filter((r) => data.some((d) => d.round_id === r.id)).map((r) => (
            <div key={r.id}>
              <h3 className="mb-1 font-bold">{nameOf(r, i18n.language)}</h3>
              <ul className="divide-y rounded border">
                {data.filter((d) => d.round_id === r.id).map((d) => (
                  <li key={`${d.student.id}-${d.direction}`} className="flex items-center justify-between gap-2 p-2 text-sm">
                    <bdi>{nameOf(d.student, i18n.language)}</bdi>
                    <span className={`rounded-full px-2 py-0.5 text-xs ${d.direction === 'pickup' ? 'bg-sky-tint' : 'bg-amber-100 text-amber-800'}`}>{d.direction === 'pickup' ? t('transport.pickupShort') : t('transport.dropoffShort')}</span>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}
      <div className="mt-4 flex justify-end"><button type="button" className="rounded border px-4 py-1.5" onClick={onClose}>{t('transport.close')}</button></div>
    </Modal>
  )
}

// One way of the ride: the round (morning going, evening coming back) and the bus. Any active bus can be chosen: it starts serving the round.
function Leg({ way, period, overview, value, onChange }) {
  const { t, i18n } = useTranslation()
  const rounds = overview.rounds.filter((r) => r.period === period)
  const busLabel = (b) => {
    const seats = b.load[value.round_id] ?? 0
    return `${t('transport.busNo', { number: b.number })} · ${b.area_from} → ${b.area_to} (${seats}/${b.capacity})${b.round_ids.includes(Number(value.round_id)) ? '' : ` — ${t('transport.willJoinRound')}`}`
  }
  return (
    <fieldset className="space-y-2 rounded-lg border p-3">
      <legend className="px-1 text-sm font-bold">{t(`transport.${way}`)}</legend>
      <label className="block text-sm">{t(`transport.${period}Round`)}
        <select className="mt-1 w-full rounded border p-2" value={value.round_id} onChange={(e) => onChange({ round_id: e.target.value, bus_id: e.target.value ? value.bus_id : '' })}>
          <option value="">{t('transport.noBus')}</option>
          {rounds.map((r) => <option key={r.id} value={r.id}>{nameOf(r, i18n.language)}{r.starts_at ? ` (${r.starts_at})` : ''}</option>)}
        </select>
      </label>
      <label className="block text-sm">{t('transport.bus')}
        <select disabled={!value.round_id} className="mt-1 w-full rounded border p-2" value={value.bus_id} onChange={(e) => onChange({ ...value, bus_id: e.target.value })}>
          <option value="">{t('transport.noBus')}</option>
          {overview.buses.filter((b) => b.is_active).map((b) => <option key={b.id} value={b.id}>{busLabel(b)}</option>)}
        </select>
      </label>
    </fieldset>
  )
}

function AssignDialog({ student, overview, onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const ride = student.ride
  const [pickup, setPickup] = useState({ round_id: ride?.pickup_round?.id ?? '', bus_id: ride?.pickup_bus?.id ?? '' })
  const [dropoff, setDropoff] = useState({ round_id: ride?.dropoff_round?.id ?? '', bus_id: ride?.dropoff_bus?.id ?? '' })
  const [error, setError] = useState('')
  const num = (v) => (v ? Number(v) : null)
  const save = useMutation({
    mutationFn: () => api.put(`/transport/students/${student.id}`, { pickup_round_id: num(pickup.round_id), pickup_bus_id: num(pickup.bus_id), dropoff_round_id: num(dropoff.round_id), dropoff_bus_id: num(dropoff.bus_id) }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['transport'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const ready = (pickup.round_id && pickup.bus_id) || (dropoff.round_id && dropoff.bus_id)
  return (
    <Modal open size="lg" title={t('transport.rideOf', { name: nameOf(student, i18n.language) })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <div className="grid gap-3 md:grid-cols-2">
          <Leg way="pickup" period="morning" overview={overview} value={pickup} onChange={setPickup} />
          <Leg way="dropoff" period="evening" overview={overview} value={dropoff} onChange={setDropoff} />
        </div>
        <p className="text-xs text-slate-500">{t('transport.seatsHint')}</p>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending || !ready} className="rounded bg-brand px-5 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

function BusesTab({ overview }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [dialog, setDialog] = useState(null)
  const [del, setDel] = useState(null)
  const [error, setError] = useState('')
  const remove = useMutation({ mutationFn: (b) => api.delete(`/transport/buses/${b.id}`), onSuccess: () => { client.invalidateQueries({ queryKey: ['transport'] }); setDel(null) }, onError: (err) => { setDel(null); setError(errorOf(err, t('admin.actions.error'))) } })
  return (
    <div className="space-y-4">
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      <button type="button" className="inline-flex items-center gap-2 rounded bg-brand px-4 py-2 text-white" onClick={() => setDialog({ type: 'bus' })}><Plus size={16} aria-hidden />{t('transport.newBus')}</button>
      {overview.buses.length === 0 && <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('transport.noBuses')}</p>}
      <div className="grid gap-4 md:grid-cols-2">
        {overview.buses.map((b) => (
          <article key={b.id} className={`space-y-3 rounded-lg border bg-white p-4 ${b.is_active ? '' : 'opacity-60'}`}>
            <header className="flex items-start justify-between gap-2">
              <div>
                <h3 className="flex items-center gap-2 text-lg font-extrabold"><Bus aria-hidden size={20} />{t('transport.busNo', { number: b.number })}{!b.is_active && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal">{t('transport.inactive')}</span>}</h3>
                <p className="text-sm">{t('transport.driver')}: <bdi>{b.driver?.name ?? b.driver_name}</bdi>{(b.driver?.phone ?? b.driver_phone) && <> · <bdi dir="ltr">{b.driver?.phone ?? b.driver_phone}</bdi></>}</p>
                {b.escort && <p className="text-sm">{t('transport.escort')}: <bdi>{b.escort.name}</bdi>{b.escort.phone && <> · <bdi dir="ltr">{b.escort.phone}</bdi></>}</p>}
                <p className="text-sm text-slate-600">{t('transport.areaFromTo', { from: b.area_from, to: b.area_to })}</p>
              </div>
              <span className="flex gap-1">
                <button type="button" aria-label={t('admin.actions.edit')} className="rounded border p-1.5" onClick={() => setDialog({ type: 'bus', bus: b })}><Pencil size={16} aria-hidden /></button>
                <button type="button" aria-label={t('admin.actions.delete')} className="rounded border border-red-300 p-1.5 text-red-700" onClick={() => setDel(b)}><Trash2 size={16} aria-hidden /></button>
              </span>
            </header>
            <ul className="space-y-1 text-sm">
              {overview.rounds.filter((r) => b.round_ids.includes(r.id)).map((r) => (
                <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 rounded bg-slate-50 px-3 py-1.5">
                  <span className="font-bold">{nameOf(r, i18n.language)} <span className="font-normal text-slate-500">· {t(`transport.periods.${r.period}`)}</span></span>
                  <span>{t('transport.loadLine', { count: b.load[r.id] ?? 0, seats: b.capacity })}</span>
                </li>
              ))}
              {b.round_ids.length === 0 && <li className="text-slate-500">{t('transport.noRoundsForBus')}</li>}
            </ul>
            <button type="button" className="inline-flex items-center gap-2 rounded border px-3 py-1.5 text-sm text-brand" onClick={() => setDialog({ type: 'riders', bus: b })}><Users size={16} aria-hidden />{t('transport.riders')}</button>
          </article>
        ))}
      </div>
      {dialog?.type === 'bus' && <BusDialog bus={dialog.bus} rounds={overview.rounds} drivers={overview.drivers ?? []} escorts={overview.escorts ?? []} onClose={() => setDialog(null)} />}
      {dialog?.type === 'riders' && <RidersDialog bus={dialog.bus} rounds={overview.rounds} onClose={() => setDialog(null)} />}
      <ConfirmDialog open={Boolean(del)} message={t('transport.deleteBus', { number: del?.number })} onConfirm={() => remove.mutate(del)} onCancel={() => setDel(null)} busy={remove.isPending} />
    </div>
  )
}

function RoundsTab({ overview }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [dialog, setDialog] = useState(null)
  const [del, setDel] = useState(null)
  const [error, setError] = useState('')
  const remove = useMutation({ mutationFn: (r) => api.delete(`/transport/rounds/${r.id}`), onSuccess: () => { client.invalidateQueries({ queryKey: ['transport'] }); setDel(null) }, onError: (err) => { setDel(null); setError(errorOf(err, t('admin.actions.error'))) } })
  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">{t('transport.roundsHelp')}</p>
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      <button type="button" className="inline-flex items-center gap-2 rounded bg-brand px-4 py-2 text-white" onClick={() => setDialog({})}><Plus size={16} aria-hidden />{t('transport.newRound')}</button>
      {PERIODS.map((p) => (
        <div key={p} className="space-y-2">
          <h2 className="text-lg font-bold">{t(`transport.periods.${p}`)}</h2>
          {overview.rounds.filter((r) => r.period === p).length === 0 && <p className="text-sm text-slate-500">{t('transport.noRoundsInPeriod')}</p>}
          <ul className="grid gap-3 sm:grid-cols-3">
            {overview.rounds.filter((r) => r.period === p).map((r) => (
              <li key={r.id} className="space-y-2 rounded-lg border bg-white p-4">
                <h3 className="text-lg font-extrabold">{nameOf(r, i18n.language)}</h3>
                <p className="text-sm text-slate-600">{r.starts_at ? <bdi dir="ltr">{r.starts_at}{r.ends_at ? ` – ${r.ends_at}` : ''}</bdi> : t('transport.noTimes')}</p>
                <p className="text-sm">{t('transport.studentsCount', { count: r.riders })}</p>
                <span className="flex gap-1">
                  <button type="button" aria-label={t('admin.actions.edit')} className="rounded border p-1.5" onClick={() => setDialog({ round: r })}><Pencil size={16} aria-hidden /></button>
                  <button type="button" aria-label={t('admin.actions.delete')} className="rounded border border-red-300 p-1.5 text-red-700" onClick={() => setDel(r)}><Trash2 size={16} aria-hidden /></button>
                </span>
              </li>
            ))}
          </ul>
        </div>
      ))}
      {dialog && <RoundDialog round={dialog.round} onClose={() => setDialog(null)} />}
      <ConfirmDialog open={Boolean(del)} message={t('transport.deleteRound', { name: nameOf(del, i18n.language) })} onConfirm={() => remove.mutate(del)} onCancel={() => setDel(null)} busy={remove.isPending} />
    </div>
  )
}

function StudentsTab({ overview }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [search, setSearch] = useState('')
  const [only, setOnly] = useState('all')
  const [edit, setEdit] = useState(null)
  const [del, setDel] = useState(null)
  const { data } = useQuery({ queryKey: ['transport', 'assignments', search, only], queryFn: async () => (await api.get('/transport/assignments', { params: { search, only } })).data.data })
  const remove = useMutation({ mutationFn: (s) => api.delete(`/transport/students/${s.id}`), onSuccess: () => { client.invalidateQueries({ queryKey: ['transport'] }); setDel(null) } })
  const leg = (round, bus) => (bus ? `${t('transport.busNo', { number: bus.number })} · ${nameOf(round, i18n.language)}` : '—')
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        {['all', 'riding', 'none'].map((k) => (
          <button key={k} type="button" aria-pressed={only === k} className={`rounded-full border px-3 py-1 text-sm ${only === k ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => setOnly(k)}>{t(`transport.filter.${k}`)}</button>
        ))}
        <input type="search" aria-label={t('admin.actions.search')} placeholder={t('admin.actions.search')} className="ms-auto rounded border p-1.5 text-sm" value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>
      <div className="overflow-x-auto rounded-lg border bg-white">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-50"><tr>{['student', 'classroom', 'pickup', 'dropoff'].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`transport.cols.${c}`)}</th>)}<th><span className="sr-only">{t('a11y.actions')}</span></th></tr></thead>
          <tbody>
            {(data ?? []).map((s) => (
              <tr key={s.id} className="border-t">
                <td className="p-2 font-medium"><bdi>{nameOf(s, i18n.language)}</bdi></td>
                <td className="p-2 text-slate-600">{s.classroom ?? '—'}</td>
                <td className="p-2">{leg(s.ride?.pickup_round, s.ride?.pickup_bus)}</td>
                <td className="p-2">{leg(s.ride?.dropoff_round, s.ride?.dropoff_bus)}</td>
                <td className="p-2"><span className="flex justify-end gap-2">
                  <button type="button" className="rounded border px-3 py-1 text-brand" onClick={() => setEdit(s)}>{s.ride ? t('admin.actions.edit') : t('transport.assign')}</button>
                  {s.ride && <button type="button" aria-label={t('admin.actions.delete')} className="rounded border border-red-300 p-1.5 text-red-700" onClick={() => setDel(s)}><Trash2 size={16} aria-hidden /></button>}
                </span></td>
              </tr>
            ))}
            {data?.length === 0 && <tr><td colSpan={5} className="p-4 text-center text-slate-500">{t('transport.noStudents')}</td></tr>}
          </tbody>
        </table>
      </div>
      {edit && <AssignDialog student={edit} overview={overview} onClose={() => setEdit(null)} />}
      <ConfirmDialog open={Boolean(del)} message={t('transport.removeRide', { name: nameOf(del, i18n.language) })} confirmLabel={t('transport.remove')} onConfirm={() => remove.mutate(del)} onCancel={() => setDel(null)} busy={remove.isPending} />
    </div>
  )
}

// School transport: buses (driver, area, seats, rounds), the rounds of the day, and where each student rides.
export default function TransportPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState('buses')
  const { data } = useQuery({ queryKey: ['transport', 'overview'], queryFn: async () => (await api.get('/transport')).data })
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('transport.title')}</h1>
      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : (
        <>
          <p className="text-sm text-slate-600">{t('transport.summary', { buses: data.buses.length, rounds: data.rounds.length, riders: data.riders })}</p>
          <div role="tablist" className="flex flex-wrap gap-2">
            {['buses', 'rounds', 'students'].map((k) => (
              <button key={k} type="button" role="tab" aria-selected={tab === k} className={`rounded-full border px-4 py-1.5 text-sm ${tab === k ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => setTab(k)}>{t(`transport.tabs.${k}`)}</button>
            ))}
          </div>
          {tab === 'buses' && <BusesTab overview={data} />}
          {tab === 'rounds' && <RoundsTab overview={data} />}
          {tab === 'students' && <StudentsTab overview={data} />}
        </>
      )}
    </section>
  )
}
