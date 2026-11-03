import { useTranslation } from 'react-i18next'
import { Bus, MapPin, Phone } from 'lucide-react'

// One way of a student's ride: which bus, its driver and the area it covers.
function Leg({ title, round, bus }) {
  const { t, i18n } = useTranslation()
  return (
    <div className="rounded-lg border bg-snow p-4">
      <p className="text-sm font-bold text-slate-500">{title}</p>
      {round && bus && (
        <p className="mt-1 inline-block rounded-full bg-sky-tint px-3 py-0.5 text-xs font-bold text-deep-harbor">
          {round[`name_${i18n.language}`] || round.name_ar}{round.starts_at ? <> · <bdi dir="ltr">{round.starts_at}{round.ends_at ? ` – ${round.ends_at}` : ''}</bdi></> : null}
        </p>
      )}
      {!bus ? <p className="mt-2 text-slate-500">{t('transport.noBusLeg')}</p> : (
        <div className="mt-2 space-y-1">
          <p className="flex items-center gap-2 text-lg font-extrabold text-deep-harbor"><Bus aria-hidden size={20} />{t('transport.busNo', { number: bus.number })}</p>
          <p>{t('transport.driver')}: <bdi>{bus.driver_name}</bdi></p>
          {bus.driver_phone && <p className="flex items-center gap-2 text-sm"><Phone aria-hidden size={14} /><bdi dir="ltr">{bus.driver_phone}</bdi></p>}
          <p className="flex items-center gap-2 text-sm text-slate-600"><MapPin aria-hidden size={14} />{t('transport.areaFromTo', { from: bus.area_from, to: bus.area_to })}</p>
        </div>
      )}
    </div>
  )
}

// A student's ride as the family sees it: the round and the bus to school and the bus home.
export default function RideCard({ ride }) {
  const { t } = useTranslation()
  if (!ride || (!ride.pickup_bus && !ride.dropoff_bus)) return <p className="rounded-lg border bg-snow p-6 text-center text-slate-600">{t('transport.notRiding')}</p>
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Leg title={t('transport.pickup')} round={ride.pickup_round} bus={ride.pickup_bus} />
      <Leg title={t('transport.dropoff')} round={ride.dropoff_round} bus={ride.dropoff_bus} />
    </div>
  )
}
