import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import Modal from '../../../components/shared/Modal'
import { MonthInput } from '../../../components/shared/DateInput'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const CATEGORIES = ['private_lessons', 'summer_club', 'club', 'other']

function TemplateDialog({ template, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ name: template?.name ?? '', kind: template?.kind ?? 'bonus', type: template?.type ?? 'amount', value: template?.value ?? '', category: template?.category ?? '' })
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => { const body = { ...form, category: form.category || null }; return template ? api.put(`/payroll/templates/${template.id}`, body) : api.post('/payroll/templates', body) },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['payroll'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  return (
    <Modal open title={template ? t('payroll.editRule') : t('payroll.newRule')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('payroll.ruleName')}<input required className="mt-1 w-full rounded border p-2" placeholder={t('payroll.ruleNameHint')} value={form.name} onChange={set('name')} /></label>
        <div className="grid gap-3 sm:grid-cols-3">
          <label className="block text-sm">{t('payroll.kind')}<select className="mt-1 w-full rounded border p-2" value={form.kind} onChange={set('kind')}>{['bonus', 'allowance', 'deduction'].map((k) => <option key={k} value={k}>{t(`payroll.kinds.${k}`)}</option>)}</select></label>
          <label className="block text-sm">{t('payroll.rulesType')}<select className="mt-1 w-full rounded border p-2" value={form.type} onChange={set('type')}><option value="amount">{t('payroll.rulesAmount')}</option><option value="percent">{t('payroll.rulesPercent')}</option></select></label>
          <label className="block text-sm">{t('payroll.value')}<input required type="number" step="0.01" min="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.value} onChange={set('value')} /></label>
        </div>
        <label className="block text-sm">{t('payroll.category')}
          <select className="mt-1 w-full rounded border p-2" value={form.category} onChange={set('category')}><option value="">—</option>{CATEGORIES.map((c) => <option key={c} value={c}>{t(`payroll.categories.${c}`)}</option>)}</select>
        </label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

// Who gets this rule every month, and for which months: tick the people and pick the first (and last) month.
function AssignDialog({ template, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [picked, setPicked] = useState([])
  const [search, setSearch] = useState('')
  const [form, setForm] = useState({ starts_on: '', ends_on: '' })
  const [error, setError] = useState('')
  const staff = useQuery({ queryKey: ['payroll', 'staff'], queryFn: async () => (await api.get('/payroll/staff')).data })
  const list = useQuery({ queryKey: ['payroll', 'assignments', template.id], queryFn: async () => (await api.get(`/payroll/templates/${template.id}/assignments`)).data.data })
  const refresh = () => { client.invalidateQueries({ queryKey: ['payroll', 'assignments'] }); client.invalidateQueries({ queryKey: ['payroll', 'templates'] }) }
  const add = useMutation({
    mutationFn: () => api.post(`/payroll/templates/${template.id}/assignments`, { user_ids: picked, starts_on: form.starts_on, ends_on: form.ends_on || null }),
    onSuccess: () => { setPicked([]); setError(''); refresh() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const remove = useMutation({ mutationFn: (id) => api.delete(`/payroll/assignments/${id}`), onSuccess: refresh })
  const q = search.trim().toLowerCase()
  const people = (staff.data?.data ?? []).filter((s) => !q || s.name.toLowerCase().includes(q))
  const toggle = (id) => setPicked(picked.includes(id) ? picked.filter((x) => x !== id) : [...picked, id])
  return (
    <Modal open size="lg" title={t('payroll.assignTitle', { name: template.name })} onClose={onClose}>
      <div className="space-y-4">
        <p className="text-sm text-slate-600">{t('payroll.assignHelp')}</p>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); add.mutate() }}>
          <input type="search" aria-label={t('admin.actions.search')} placeholder={t('admin.actions.search')} className="w-full rounded border p-2 text-sm" value={search} onChange={(e) => setSearch(e.target.value)} />
          <ul className="max-h-48 space-y-1 overflow-y-auto rounded border p-2">
            {people.map((s) => <li key={s.id}><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={picked.includes(s.id)} onChange={() => toggle(s.id)} />{s.name}</label></li>)}
          </ul>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm">{t('payroll.fromMonth')}<MonthInput required className="mt-1 w-full rounded border p-2" value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value })} /></label>
            <label className="block text-sm">{t('payroll.toMonth')}<MonthInput className="mt-1 w-full rounded border p-2" value={form.ends_on} onChange={(e) => setForm({ ...form, ends_on: e.target.value })} /></label>
          </div>
          <p className="text-xs text-slate-500">{t('payroll.toMonthHint')}</p>
          <button type="submit" disabled={add.isPending || picked.length === 0 || !form.starts_on} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('payroll.assignAction', { count: picked.length })}</button>
        </form>
        <div>
          <p className="mb-1 text-sm font-medium">{t('payroll.assignedNow')}</p>
          <ul className="space-y-1">
            {(list.data ?? []).map((a) => (
              <li key={a.id} className="flex items-center justify-between gap-2 rounded border p-2 text-sm"><span>{a.user.name} · <span dir="ltr">{a.starts_on} → {a.ends_on ?? '∞'}</span></span><button type="button" aria-label={t('admin.actions.delete')} className="text-red-700" onClick={() => remove.mutate(a.id)}>✕</button></li>
            ))}
            {(list.data ?? []).length === 0 && <li className="text-sm text-slate-500">{t('payroll.noAssigned')}</li>}
          </ul>
        </div>
        <div className="flex justify-end"><button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('payroll.close')}</button></div>
      </div>
    </Modal>
  )
}

// The school's own bonus and deduction rules: written once here, then given to staff from a payroll.
export default function TemplatesPage({ base }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [dialog, setDialog] = useState(null)
  const [dropping, setDropping] = useState(null)
  const { data } = useQuery({ queryKey: ['payroll', 'templates'], queryFn: async () => (await api.get('/payroll/templates')).data })
  const drop = useMutation({ mutationFn: (id) => api.delete(`/payroll/templates/${id}`), onSuccess: () => client.invalidateQueries({ queryKey: ['payroll'] }) })
  if (!data) return <p className="text-slate-500">{t('common.loading')}</p>
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('payroll.templatesTitle')}</h1>
        <span className="flex gap-2"><Link to={`${base}/payroll`} className="rounded border px-3 py-1.5 text-sm text-brand">{t('payroll.title')}</Link><button type="button" className="rounded bg-brand px-3 py-1.5 text-sm text-white" onClick={() => setDialog({})}>{t('payroll.newRule')}</button></span>
      </div>
      <p className="text-sm text-slate-600">{t('payroll.templatesHelp')}</p>
      <div className="overflow-x-auto rounded-lg border bg-white">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-50"><tr>{['ruleName', 'kind', 'value', 'category'].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`payroll.${c}`)}</th>)}<th><span className="sr-only">{t('a11y.actions')}</span></th></tr></thead>
          <tbody>
            {data.data.map((r) => (
              <tr key={r.id} className="border-t">
                <td className="p-2 font-medium">{r.name}</td>
                <td className="p-2"><span className={`rounded-full px-2 py-0.5 text-xs ${r.kind === 'deduction' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-800'}`}>{t(`payroll.kinds.${r.kind}`)}</span></td>
                <td className="p-2" dir="ltr">{r.type === 'percent' ? `${Number(r.value)}%` : `${r.value} ${data.currency}`}</td>
                <td className="p-2 text-slate-600">{r.category ? t(`payroll.categories.${r.category}`) : '—'}</td>
                <td className="p-2"><span className="flex justify-end gap-2"><button type="button" className="rounded bg-brand px-2 py-1 text-white" onClick={() => setDialog({ assign: r })}>{t('payroll.assignStaff')}{r.assignments_count > 0 ? ` (${r.assignments_count})` : ''}</button><button type="button" className="rounded border px-2 py-1 text-brand" onClick={() => setDialog(r)}>{t('admin.actions.edit')}</button><button type="button" className="rounded border border-red-300 px-2 py-1 text-red-700" onClick={() => setDropping(r)}>{t('admin.actions.delete')}</button></span></td>
              </tr>
            ))}
            {data.data.length === 0 && <tr><td colSpan={5} className="p-4 text-center text-slate-500">{t('payroll.noTemplates')}</td></tr>}
          </tbody>
        </table>
      </div>
      <ConfirmDialog open={Boolean(dropping)} message={t('payroll.deleteRuleTitle')} description={dropping ? `${dropping.name} — ${t('payroll.deleteRuleConfirm')}` : ''} onConfirm={() => { drop.mutate(dropping.id); setDropping(null) }} onCancel={() => setDropping(null)} />
      {dialog?.assign && <AssignDialog template={dialog.assign} onClose={() => setDialog(null)} />}
      {dialog && !dialog.assign && <TemplateDialog template={dialog.id ? dialog : null} onClose={() => setDialog(null)} />}
    </section>
  )
}
