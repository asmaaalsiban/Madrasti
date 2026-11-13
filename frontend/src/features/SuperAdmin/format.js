// A school's end date as a day: the API sends midnight (UTC) of that day, so the first ten characters are the date.
export const dayOf = (iso) => (iso ? String(iso).slice(0, 10) : '')

export const formatDay = (iso, language) => (iso
  ? new Date(`${dayOf(iso)}T12:00:00`).toLocaleDateString(language === 'ar' ? 'ar-JO-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
  : '')

export const formatWhen = (iso, language) => new Date(iso).toLocaleString(language === 'ar' ? 'ar-JO-u-nu-latn' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })

export const STATES = ['active', 'expired', 'suspended', 'exit_window', 'closed']
export const STATE_STYLE = {
  active: 'bg-green-100 text-green-800',
  expired: 'bg-amber-100 text-amber-800',
  suspended: 'bg-red-100 text-red-700',
  exit_window: 'bg-sky-100 text-sky-800',
  closed: 'bg-slate-200 text-slate-700',
}
