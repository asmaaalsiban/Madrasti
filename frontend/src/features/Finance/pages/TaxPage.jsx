import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// Tax (VAT / sales tax): off by default. Prices include the tax; the school picks which fee kinds carry it.
export default function TaxPage() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const { data } = useQuery({ queryKey: ['finance', 'tax-settings'], queryFn: async () => (await api.get('/finance/tax-settings')).data })
  const [edit, setEdit] = useState(null)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const save = useMutation({
    mutationFn: (body) => api.put('/finance/tax-settings', body),
    onSuccess: () => { setSaved(true); setError(''); setEdit(null); client.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  if (!data) return <p className="text-slate-500">{t('common.loading')}</p>
  const form = edit ?? data.data
  const can = data.can_manage
  const set = (k, v) => { setSaved(false); setEdit({ ...form, [k]: v }) }
  const toggleKind = (k) => set('fee_kinds', form.fee_kinds.includes(k) ? form.fee_kinds.filter((x) => x !== k) : [...form.fee_kinds, k])
  return (
    <section className="max-w-2xl space-y-4">
      <h1 className="text-xl font-bold">{t('tax.title')}</h1>
      <p className="text-sm text-slate-600">{t('tax.intro')}</p>
      <form className="space-y-3 rounded-lg border bg-white p-4" onSubmit={(e) => { e.preventDefault(); save.mutate({ ...form, rate: Number(form.rate || 0) }) }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {saved && <p role="status" className="rounded bg-green-50 p-2 text-sm text-green-800">{t('tax.saved')}</p>}
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" disabled={!can} checked={form.enabled} onChange={(e) => set('enabled', e.target.checked)} />{t('tax.enabled')}</label>
        <div className="grid gap-3 sm:grid-cols-3">
          <label className="block text-sm">{t('tax.name')}<input required disabled={!can} className="mt-1 w-full rounded border p-2" value={form.name} onChange={(e) => set('name', e.target.value)} /></label>
          <label className="block text-sm">{t('tax.rate')}<input required type="number" min="0" max="100" step="0.01" dir="ltr" disabled={!can} className="mt-1 w-full rounded border p-2" value={form.rate} onChange={(e) => set('rate', e.target.value)} /></label>
          <label className="block text-sm">{t('tax.number')}<input dir="ltr" disabled={!can} className="mt-1 w-full rounded border p-2" value={form.number} onChange={(e) => set('number', e.target.value)} /></label>
        </div>
        <fieldset className="text-sm">
          <legend className="mb-1 font-bold">{t('tax.feeKinds')}</legend>
          <div className="flex flex-wrap gap-3">{data.kinds.map((k) => <label key={k} className="flex items-center gap-1"><input type="checkbox" disabled={!can} checked={form.fee_kinds.includes(k)} onChange={() => toggleKind(k)} />{t(`tax.kinds.${k}`, { defaultValue: k })}</label>)}</div>
        </fieldset>
        {can && <div className="flex justify-end"><button type="submit" disabled={save.isPending} className="rounded bg-brand px-5 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button></div>}
      </form>
    </section>
  )
}
