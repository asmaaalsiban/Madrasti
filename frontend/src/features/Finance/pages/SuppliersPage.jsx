import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Pencil, Plus } from 'lucide-react'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import Money from '../components/Money'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

function SupplierDialog({ supplier, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ name: supplier?.name ?? '', phone: supplier?.phone ?? '', email: supplier?.email ?? '', notes: supplier?.notes ?? '', is_active: supplier?.is_active ?? true })
  const [error, setError] = useState('')
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () => { const body = { ...form, phone: form.phone || null, email: form.email || null, notes: form.notes || null }; return supplier ? api.put(`/finance/suppliers/${supplier.id}`, body) : api.post('/finance/suppliers', body) },
    onSuccess: () => { client.invalidateQueries({ queryKey: ['finance'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  return (
    <Modal open title={supplier ? t('expenses.editSupplier') : t('expenses.newSupplier')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('expenses.supplierName')}<input required className="mt-1 w-full rounded border p-2" value={form.name} onChange={set('name')} /></label>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{t('expenses.phone')}<input dir="ltr" className="mt-1 w-full rounded border p-2" value={form.phone} onChange={set('phone')} /></label>
          <label className="block text-sm">{t('expenses.email')}<input type="email" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.email} onChange={set('email')} /></label>
        </div>
        <label className="block text-sm">{t('expenses.notes')}<input className="mt-1 w-full rounded border p-2" value={form.notes} onChange={set('notes')} /></label>
        {supplier && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />{t('money.active')}</label>}
        <div className="flex justify-end gap-2">
          <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</InteractiveHoverButton>
          <InteractiveHoverButton type="submit" disabled={save.isPending} className="px-4 py-1.5 disabled:opacity-50">{t('admin.actions.save')}</InteractiveHoverButton>
        </div>
      </form>
    </Modal>
  )
}

// The people the school buys from, and what it still owes each of them.
export default function SuppliersPage({ base }) {
  const { t } = useTranslation()
  const [dialog, setDialog] = useState(null)
  const { data } = useQuery({ queryKey: ['finance', 'suppliers'], queryFn: async () => (await api.get('/finance/suppliers')).data })
  const c = data?.currency
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('expenses.suppliers')}</h1>
        <div className="flex gap-2">
          <Link to={`${base}/finance/expenses`} className="rounded border px-4 py-1.5 text-brand">{t('expenses.title')}</Link>
          {data?.can_manage && <InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={() => setDialog({})}><Plus size={16} aria-hidden />{t('expenses.newSupplier')}</InteractiveHoverButton>}
        </div>
      </div>
      {!data ? <p className="text-slate-500">{t('common.loading')}</p> : data.data.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('expenses.noSuppliers')}</p> : (
        <div className="overflow-x-auto rounded-lg border bg-white">
          <table className="w-full text-start text-sm">
            <thead className="bg-slate-50"><tr>{['name', 'phone', 'bills', 'bought', 'owed'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`expenses.supCols.${k}`)}</th>)}<th><span className="sr-only">{t('a11y.actions')}</span></th></tr></thead>
            <tbody>
              {data.data.map((s) => (
                <tr key={s.id} className={`border-t ${s.is_active ? '' : 'text-slate-400'}`}>
                  <td className="p-2 font-medium"><bdi>{s.name}</bdi>{!s.is_active && <span className="ms-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal">{t('money.off')}</span>}</td>
                  <td className="p-2" dir="ltr">{s.phone ?? '—'}</td>
                  <td className="p-2">{s.bills}</td>
                  <td className="p-2"><Money value={s.bought} currency={c} /></td>
                  <td className={`p-2 font-bold ${Number(s.owed) > 0 ? 'text-red-700' : ''}`}><Money value={s.owed} currency={c} /></td>
                  <td className="p-2 text-end">{data.can_manage && <button type="button" aria-label={t('admin.actions.edit')} className="rounded border p-1.5" onClick={() => setDialog(s)}><Pencil size={16} aria-hidden /></button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {dialog && <SupplierDialog supplier={dialog.id ? dialog : null} onClose={() => setDialog(null)} />}
    </section>
  )
}
