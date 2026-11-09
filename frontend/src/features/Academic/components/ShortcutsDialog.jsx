import { useTranslation } from 'react-i18next'
import { ArrowDown, ArrowUp, Check, Plus, RotateCcw, X } from 'lucide-react'
import Modal from '../../../components/shared/Modal'
import { GROUPS } from '../../../layouts/AdminLayout'
import { swap } from '../../Layout/arrange'

// The pages a shortcut can open: every entry of the side menu except the overview (which is always first).
export const CATALOG = GROUPS.flatMap((g) => g.items.filter((i) => i[0] !== '/admin').map(([path, key, Icon]) => ({ path, key, Icon, group: g.key })))
export const DEFAULT_SHORTCUTS = ['/admin/students', '/admin/timetable', '/admin/finance', '/admin/staff', '/admin/attendance']
const MAX = 12

/** Where the principal adds, removes and reorders the shortcuts of the pill menu on his overview. */
export default function ShortcutsDialog({ open, onClose, paths, onChange, onReset, custom }) {
  const { t } = useTranslation()
  const byPath = Object.fromEntries(CATALOG.map((c) => [c.path, c]))
  const chosen = paths.filter((p) => byPath[p])
  const full = chosen.length >= MAX
  return (
    <Modal open={open} size="lg" title={t('dashboard.shortcuts.title')} onClose={onClose}>
      <div className="space-y-5">
        <p className="text-sm text-slate-600">{t('dashboard.shortcuts.hint')}</p>

        <section aria-label={t('dashboard.shortcuts.yours')}>
          <h3 className="mb-2 text-sm font-bold text-deep-harbor">{t('dashboard.shortcuts.yours')} <span className="font-normal text-slate-500">({chosen.length}/{MAX})</span></h3>
          {chosen.length === 0 ? <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-500">{t('dashboard.shortcuts.none')}</p> : (
            <ul className="space-y-1.5">
              {chosen.map((p, n) => {
                const { Icon, key } = byPath[p]
                const label = t(`admin.nav.${key}`)
                return (
                  <li key={p} className="flex items-center gap-2 rounded-lg border bg-white px-3 py-1.5">
                    <Icon size={16} aria-hidden className="text-brand" />
                    <span className="flex-1 text-sm font-medium">{label}</span>
                    <button type="button" disabled={n === 0} aria-label={`${t('dashboard.shortcuts.up')} ${label}`} className="rounded border p-1 disabled:opacity-30" onClick={() => onChange(swap(chosen, p, -1))}><ArrowUp size={14} aria-hidden /></button>
                    <button type="button" disabled={n === chosen.length - 1} aria-label={`${t('dashboard.shortcuts.down')} ${label}`} className="rounded border p-1 disabled:opacity-30" onClick={() => onChange(swap(chosen, p, 1))}><ArrowDown size={14} aria-hidden /></button>
                    <button type="button" aria-label={`${t('dashboard.shortcuts.remove')} ${label}`} className="rounded border border-red-300 p-1 text-red-700" onClick={() => onChange(chosen.filter((x) => x !== p))}><X size={14} aria-hidden /></button>
                  </li>
                )
              })}
            </ul>
          )}
        </section>

        <section aria-label={t('dashboard.shortcuts.add')}>
          <h3 className="mb-2 text-sm font-bold text-deep-harbor">{t('dashboard.shortcuts.add')}</h3>
          {full && <p className="mb-2 text-sm text-amber-700">{t('dashboard.shortcuts.full', { max: MAX })}</p>}
          <div className="space-y-3">
            {GROUPS.map((g) => {
              const items = CATALOG.filter((c) => c.group === g.key)
              if (items.length === 0) return null
              return (
                <div key={g.key ?? 'home'}>
                  {g.key && <p className="mb-1 text-xs font-bold text-slate-500">{t(`admin.groups.${g.key}`)}</p>}
                  <div className="flex flex-wrap gap-1.5">
                    {items.map(({ path, key, Icon }) => {
                      const on = chosen.includes(path)
                      const label = t(`admin.nav.${key}`)
                      return (
                        <button key={path} type="button" disabled={!on && full} aria-pressed={on} aria-label={`${on ? t('dashboard.shortcuts.remove') : t('dashboard.shortcuts.addOne')} ${label}`}
                          className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition disabled:opacity-40 ${on ? 'border-brand bg-sky-tint font-bold text-deep-harbor' : 'hover:border-brand'}`}
                          onClick={() => onChange(on ? chosen.filter((x) => x !== path) : [...chosen, path])}>
                          {on ? <Check size={14} aria-hidden /> : <Plus size={14} aria-hidden />}<Icon size={14} aria-hidden />{label}
                        </button>
                      )
                    })}
                  </div>
                </div>
              )
            })}
          </div>
        </section>

        <div className="flex items-center justify-between gap-2">
          <button type="button" disabled={!custom} className="inline-flex items-center gap-1.5 rounded border px-3 py-1.5 text-sm disabled:opacity-40" onClick={onReset}><RotateCcw size={14} aria-hidden />{t('dashboard.shortcuts.reset')}</button>
          <button type="button" className="rounded bg-brand px-5 py-1.5 text-white" onClick={onClose}>{t('dashboard.shortcuts.done')}</button>
        </div>
      </div>
    </Modal>
  )
}
