import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'

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

// The school's own bonus and deduction rules: written once here, then given to staff from a payroll.
export default function TemplatesPage({ base }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [dialog, setDialog] = useState(null)
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
          <thead className="bg-slate-50"><tr>{['ruleName', 'kind', 'value', 'category'].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`payroll.${c}`)}</th>)}<th /></tr></thead>
          <tbody>
            {data.data.map((r) => (
              <tr key={r.id} className="border-t">
                <td className="p-2 font-medium">{r.name}</td>
                <td className="p-2"><span className={`rounded-full px-2 py-0.5 text-xs ${r.kind === 'deduction' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-800'}`}>{t(`payroll.kinds.${r.kind}`)}</span></td>
                <td className="p-2" dir="ltr">{r.type === 'percent' ? `${Number(r.value)}%` : `${r.value} ${data.currency}`}</td>
                <td className="p-2 text-slate-600">{r.category ? t(`payroll.categories.${r.category}`) : '—'}</td>
                <td className="p-2"><span className="flex justify-end gap-2"><button type="button" className="rounded border px-2 py-1 text-brand" onClick={() => setDialog(r)}>{t('admin.actions.edit')}</button><button type="button" className="rounded border border-red-300 px-2 py-1 text-red-700" onClick={() => window.confirm(t('payroll.deleteRuleConfirm')) && drop.mutate(r.id)}>{t('admin.actions.delete')}</button></span></td>
              </tr>
            ))}
            {data.data.length === 0 && <tr><td colSpan={5} className="p-4 text-center text-slate-500">{t('payroll.noTemplates')}</td></tr>}
          </tbody>
        </table>
      </div>
      {dialog && <TemplateDialog template={dialog.id ? dialog : null} onClose={() => setDialog(null)} />}
    </section>
  )
}
