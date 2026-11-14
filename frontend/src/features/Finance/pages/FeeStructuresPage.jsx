import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import Modal from '../../../components/shared/Modal'
import ApplyFeeDialog from '../components/ApplyFeeDialog'
import Money from '../components/Money'
import { DateInput } from '../../../components/shared/DateInput'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// The yearly fee of each grade. The accountant adds them and gives them to the students of the grade; the admin only reads.
export default function FeeStructuresPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState(null)
  const [error, setError] = useState('')
  const [removing, setRemoving] = useState(null)
  const [applying, setApplying] = useState(null)
  const name = (g) => g[`name_${i18n.language}`] || g.name_ar

  const list = useQuery({ queryKey: ['finance', 'structures'], queryFn: async () => (await api.get('/finance/fee-structures')).data })
  const options = useQuery({ queryKey: ['finance', 'options'], queryFn: async () => (await api.get('/finance/options')).data.data })
  const settings = useQuery({ queryKey: ['finance', 'settings'], queryFn: async () => (await api.get('/finance/settings')).data.data })
  const canManage = settings.data?.can_manage

  const done = () => { client.invalidateQueries({ queryKey: ['finance'] }); setForm(null); setError(''); setRemoving(null) }
  const fail = (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error'))
  const save = useMutation({
    mutationFn: () => {
      const body = { grade_level_id: Number(form.grade_level_id), name: form.name, amount: form.amount, installments_count: Number(form.installments_count), first_due: form.first_due, every_months: Number(form.every_months), auto_apply: form.auto_apply }
      return form.id ? api.put(`/finance/fee-structures/${form.id}`, body) : api.post('/finance/fee-structures', body)
    },
    onSuccess: done, onError: fail,
  })
  const remove = useMutation({ mutationFn: (id) => api.delete(`/finance/fee-structures/${id}`), onSuccess: done, onError: (err) => { setRemoving(null); fail(err) } })
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('finance.structures')}</h1>
        {canManage && options.data && <InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={() => { setError(''); setForm({ grade_level_id: String(options.data.grades[0]?.id ?? ''), name: '', amount: '', installments_count: '4', first_due: '', every_months: '1', auto_apply: true }) }}>{t('finance.addStructure')}</InteractiveHoverButton>}
      </div>
      {error && !form && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      {list.isSuccess && list.data.data.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('finance.noStructures')}</p>}
      <ul className="space-y-2">
        {(list.data?.data ?? []).map((s) => (
          <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-white p-4">
            <div>
              <p className="font-bold">{s.name} · {name(s.grade_level)}</p>
              <p className="text-sm text-slate-600"><Money value={s.amount} currency={list.data.currency} /> · {t('finance.planSummary', { count: s.installments_count, date: s.first_due ?? '—' })} · {s.auto_apply ? t('finance.automatic') : t('finance.manual')} · {t('finance.givenTo', { count: s.students })}</p>
            </div>
            {canManage && (
              <div className="flex flex-wrap gap-2">
                <InteractiveHoverButton type="button" className="px-3 py-1 text-sm" onClick={() => setApplying(s)}>{t('finance.giveToStudents')}</InteractiveHoverButton>
                <button type="button" className="rounded border px-3 py-1 text-sm" onClick={() => { setError(''); setForm({ id: s.id, grade_level_id: String(s.grade_level.id), name: s.name, amount: s.amount, installments_count: String(s.installments_count), first_due: s.first_due ?? '', every_months: String(s.every_months), auto_apply: s.auto_apply }) }}>{t('admin.actions.edit')}</button>
                <button type="button" className="rounded border border-red-300 px-3 py-1 text-sm text-red-700" onClick={() => setRemoving(s)}>{t('admin.actions.delete')}</button>
              </div>
            )}
          </li>
        ))}
      </ul>

      {form && (
        <Modal open title={form.id ? t('finance.editStructure') : t('finance.addStructure')} onClose={() => setForm(null)}>
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
            {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
            <label className="block text-sm">{t('finance.fields.grade')}
              <select required className="mt-1 w-full rounded border p-2" value={form.grade_level_id} onChange={set('grade_level_id')}>{(options.data?.grades ?? []).map((g) => <option key={g.id} value={g.id}>{name(g)}</option>)}</select>
            </label>
            <label className="block text-sm">{t('finance.fields.name')}<input required className="mt-1 w-full rounded border p-2" value={form.name} onChange={set('name')} /></label>
            <label className="block text-sm">{t('finance.fields.amount')}<input required type="number" min="0.01" step="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.amount} onChange={set('amount')} /></label>
            <label className="block text-sm">{t('finance.fields.installments')}<input required type="number" min="1" max="24" className="mt-1 w-full rounded border p-2" value={form.installments_count} onChange={set('installments_count')} /></label>
            <label className="block text-sm">{t('finance.fields.firstDue')}<DateInput required className="mt-1 w-full rounded border p-2" value={form.first_due} onChange={set('first_due')} /></label>
            <label className="block text-sm">{t('finance.fields.everyMonths')}<input required type="number" min="1" max="12" className="mt-1 w-full rounded border p-2" value={form.every_months} onChange={set('every_months')} /></label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.auto_apply} onChange={(e) => setForm({ ...form, auto_apply: e.target.checked })} />{t('finance.autoApply')}</label>
            <p className="text-xs text-slate-500">{t('finance.autoApplyHelp')}</p>
            <div className="flex justify-end gap-2">
              <button type="button" className="rounded border px-3 py-1.5" onClick={() => setForm(null)}>{t('admin.actions.cancel')}</button>
              <InteractiveHoverButton type="submit" className="px-4 py-1.5 disabled:opacity-50" disabled={save.isPending}>{t('admin.actions.save')}</InteractiveHoverButton>
            </div>
          </form>
        </Modal>
      )}
      {applying && <ApplyFeeDialog structure={applying} onClose={() => setApplying(null)} />}
      <ConfirmDialog open={Boolean(removing)} message={t('finance.confirmDeleteStructure', { name: removing?.name })} onConfirm={() => remove.mutate(removing.id)} onCancel={() => setRemoving(null)} />
    </section>
  )
}
