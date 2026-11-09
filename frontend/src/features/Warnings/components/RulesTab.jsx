import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import Modal from '../../../components/shared/Modal'

const CATEGORIES = ['absence', 'late', 'behavior', 'violence', 'academic', 'uniform', 'other']
const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const blank = { subject_type: 'student', name: '', metric: 'absent_days', threshold: 3, window_days: 30, category: 'absence', level: 1, title: '', body: '', auto_send: false, is_active: true }

function RuleDialog({ rule, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState(rule ? { ...rule, body: rule.body ?? '' } : blank)
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => { const body = { ...form, threshold: Number(form.threshold), window_days: Number(form.window_days), level: Number(form.level), body: form.body || null }; return rule ? api.put(`/warnings/rules/${rule.id}`, body) : api.post('/warnings/rules', body) },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['warnings', 'rules'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const metrics = form.subject_type === 'student' ? ['absent_days', 'late_count', 'negative_notes'] : ['absent_days', 'late_count']
  const change = (subject) => setForm({ ...form, subject_type: subject, metric: subject === 'staff' && form.metric === 'negative_notes' ? 'absent_days' : form.metric })
  return (
    <Modal open size="lg" title={rule ? t('warnings.rules.edit') : t('warnings.rules.new')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{t('warnings.rules.forWhom')}<select disabled={Boolean(rule)} className="mt-1 w-full rounded border p-2" value={form.subject_type} onChange={(e) => change(e.target.value)}>{['student', 'staff'].map((k) => <option key={k} value={k}>{t(`warnings.subject.${k}`)}</option>)}</select></label>
          <label className="block text-sm">{t('warnings.rules.name')}<input required className="mt-1 w-full rounded border p-2" value={form.name} onChange={set('name')} /></label>
        </div>
        <fieldset className="grid gap-3 rounded border p-3 sm:grid-cols-3">
          <legend className="px-1 text-sm font-bold">{t('warnings.rules.when')}</legend>
          <label className="block text-sm">{t('warnings.rules.measure')}<select className="mt-1 w-full rounded border p-2" value={form.metric} onChange={set('metric')}>{metrics.map((m) => <option key={m} value={m}>{t(`warnings.rules.metrics.${m}`)}</option>)}</select></label>
          <label className="block text-sm">{t('warnings.rules.reaches')}<input required type="number" min="1" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.threshold} onChange={set('threshold')} /></label>
          <label className="block text-sm">{t('warnings.rules.within')}<input required type="number" min="1" max="366" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.window_days} onChange={set('window_days')} /></label>
        </fieldset>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{t('warnings.category')}<select className="mt-1 w-full rounded border p-2" value={form.category} onChange={set('category')}>{CATEGORIES.map((c) => <option key={c} value={c}>{t(`warnings.categories.${c}`)}</option>)}</select></label>
          <label className="block text-sm">{t('warnings.level')}<select className="mt-1 w-full rounded border p-2" value={form.level} onChange={set('level')}>{[1, 2, 3, 4].map((l) => <option key={l} value={l}>{t(`warnings.levels.${l}`)}</option>)}</select></label>
        </div>
        <label className="block text-sm">{t('warnings.title')}<input required dir="auto" className="mt-1 w-full rounded border p-2" value={form.title} onChange={set('title')} /></label>
        <label className="block text-sm">{t('warnings.details')}<textarea rows={3} dir="auto" className="mt-1 w-full rounded border p-2" value={form.body} onChange={set('body')} /><span className="text-xs text-slate-500">{t('warnings.rules.bodyHint')}</span></label>
        <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={form.auto_send} onChange={(e) => setForm({ ...form, auto_send: e.target.checked })} /><span>{t('warnings.rules.autoSend')}<span className="block text-xs text-slate-500">{t('warnings.rules.autoSendHint')}</span></span></label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-brand px-5 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

// The school's optional warning rules: "when [measure] reaches [number] within [days], raise a warning". No rule = no automatic warning.
export default function RulesTab() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [dialog, setDialog] = useState(null)
  const [removing, setRemoving] = useState(null)
  const [message, setMessage] = useState('')
  const { data } = useQuery({ queryKey: ['warnings', 'rules'], queryFn: async () => (await api.get('/warnings/rules')).data.data })
  const refresh = () => client.invalidateQueries({ queryKey: ['warnings'] })
  const toggle = useMutation({ mutationFn: (r) => api.put(`/warnings/rules/${r.id}`, { ...r, body: r.body || null, is_active: !r.is_active }), onSuccess: refresh })
  const del = useMutation({ mutationFn: (id) => api.delete(`/warnings/rules/${id}`), onSuccess: refresh, onSettled: () => setRemoving(null) })
  const run = useMutation({ mutationFn: () => api.post('/warnings/rules/run').then((r) => r.data.data), onSuccess: (r) => { setMessage(t('warnings.rules.ran', r)); refresh() } })
  return (
    <div className="space-y-3">
      <p className="text-sm text-slate-600">{t('warnings.rules.intro')}</p>
      <div className="flex flex-wrap gap-2">
        <button type="button" className="inline-flex items-center gap-2 rounded bg-brand px-4 py-1.5 text-white" onClick={() => setDialog({})}><Plus size={16} aria-hidden />{t('warnings.rules.new')}</button>
        <button type="button" disabled={run.isPending} className="rounded border px-4 py-1.5 text-brand disabled:opacity-50" onClick={() => run.mutate()}>{t('warnings.rules.runNow')}</button>
      </div>
      {message && <p role="status" className="rounded bg-green-50 p-2 text-sm text-green-800">{message}</p>}
      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : data.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('warnings.rules.none')}</p> : (
        <ul className="space-y-2">
          {data.map((r) => (
            <li key={r.id} className={`flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-white p-3 text-sm ${r.is_active ? '' : 'opacity-60'}`}>
              <span>
                <b>{r.name}</b> <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{t(`warnings.subject.${r.subject_type}`)}</span>
                <span className="block text-slate-600">{t('warnings.rules.sentence', { measure: t(`warnings.rules.metrics.${r.metric}`), count: r.threshold, days: r.window_days, level: t(`warnings.levels.${r.level}`) })} · {r.auto_send ? t('warnings.rules.sentAtOnce') : t('warnings.rules.needsApproval')}</span>
              </span>
              <span className="flex items-center gap-3">
                <button type="button" className="text-brand underline" onClick={() => toggle.mutate(r)}>{r.is_active ? t('warnings.rules.switchOff') : t('warnings.rules.switchOn')}</button>
                <button type="button" className="text-brand underline" onClick={() => setDialog({ rule: r })}>{t('admin.actions.edit')}</button>
                <button type="button" className="text-red-700 underline" onClick={() => setRemoving(r)}>{t('admin.actions.delete')}</button>
              </span>
            </li>
          ))}
        </ul>
      )}
      {dialog && <RuleDialog rule={dialog.rule} onClose={() => setDialog(null)} />}
      <ConfirmDialog open={removing !== null} message={t('warnings.rules.confirmDelete')} confirmLabel={t('admin.actions.delete')} onConfirm={() => del.mutate(removing.id)} onCancel={() => setRemoving(null)} />
    </div>
  )
}
