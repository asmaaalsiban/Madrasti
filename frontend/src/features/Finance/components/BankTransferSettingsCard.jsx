import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2 } from 'lucide-react'
import { api } from '../../../api/client'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const blank = () => ({ bank: '', holder: '', iban: '', cliq: '' })

// Pay by bank transfer / CliQ: the school's accounts and instructions, shown to parents who choose this way.
function Form({ settings }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ enabled: settings.enabled, instructions: settings.instructions, accounts: settings.accounts.length ? settings.accounts : [blank()] })
  const [message, setMessage] = useState(null)
  const save = useMutation({
    mutationFn: () => api.put('/finance/bank-transfer-settings', { ...form, accounts: form.accounts.filter((a) => a.bank.trim()) }),
    onSuccess: () => { setMessage({ ok: true, text: t('paySettings.saved') }); client.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (err) => setMessage({ ok: false, text: errorOf(err, t('admin.actions.error')) }),
  })
  const setAccount = (i, k) => (e) => setForm({ ...form, accounts: form.accounts.map((a, j) => (j === i ? { ...a, [k]: e.target.value } : a)) })
  return (
    <form className="space-y-3 rounded-lg border bg-white p-4" aria-label={t('bankSettings.title')} onSubmit={(e) => { e.preventDefault(); setMessage(null); save.mutate() }}>
      <div><h2 className="font-bold">{t('bankSettings.title')}</h2><p className="text-sm text-slate-600">{t('bankSettings.help')}</p></div>
      {message && <p role="status" className={`rounded p-2 text-sm ${message.ok ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'}`}>{message.text}</p>}
      {form.accounts.map((a, i) => (
        <div key={i} className="grid gap-2 rounded border p-2 sm:grid-cols-4">
          <label className="block text-sm">{t('bankSettings.bank')}<input className="mt-1 w-full rounded border p-2" value={a.bank} onChange={setAccount(i, 'bank')} /></label>
          <label className="block text-sm">{t('bankSettings.holder')}<input className="mt-1 w-full rounded border p-2" value={a.holder} onChange={setAccount(i, 'holder')} /></label>
          <label className="block text-sm">IBAN<input dir="ltr" className="mt-1 w-full rounded border p-2" value={a.iban} onChange={setAccount(i, 'iban')} /></label>
          <label className="block text-sm">CliQ<span className="flex gap-1"><input dir="ltr" className="mt-1 w-full rounded border p-2" value={a.cliq} onChange={setAccount(i, 'cliq')} />
            <button type="button" aria-label={t('bankSettings.removeAccount')} className="mt-1 rounded border border-red-300 px-2 text-red-700" onClick={() => setForm({ ...form, accounts: form.accounts.filter((_, j) => j !== i) })}><Trash2 size={16} aria-hidden /></button></span></label>
        </div>
      ))}
      <button type="button" className="inline-flex items-center gap-2 rounded border px-3 py-1.5 text-sm text-brand" onClick={() => setForm({ ...form, accounts: [...form.accounts, blank()] })}><Plus size={14} aria-hidden />{t('bankSettings.addAccount')}</button>
      <label className="block text-sm">{t('bankSettings.instructions')}<textarea rows={2} className="mt-1 w-full rounded border p-2" placeholder={t('bankSettings.instructionsHint')} value={form.instructions} onChange={(e) => setForm({ ...form, instructions: e.target.value })} /></label>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.enabled} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} />{t('bankSettings.enabled')}</label>
      <InteractiveHoverButton type="submit" disabled={save.isPending} className="px-4 py-1.5 disabled:opacity-50">{t('admin.actions.save')}</InteractiveHoverButton>
    </form>
  )
}

export default function BankTransferSettingsCard() {
  const { data } = useQuery({ queryKey: ['finance', 'bank-settings'], retry: false, queryFn: async () => (await api.get('/finance/bank-transfer-settings')).data.data })
  if (!data?.accounts) return null
  return <Form key={JSON.stringify(data)} settings={data} />
}
