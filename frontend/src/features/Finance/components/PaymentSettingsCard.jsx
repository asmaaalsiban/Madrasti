import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// The school's own PayTabs account: the money from parents goes to the school's own bank account. The server key is write-only.
function Form({ settings }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ profile_id: settings.profile_id, server_key: '', base_url: settings.base_url, enabled: settings.enabled })
  const [message, setMessage] = useState(null)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const save = useMutation({
    mutationFn: () => api.put('/finance/payment-settings', form),
    onSuccess: () => { setForm({ ...form, server_key: '' }); setMessage({ ok: true, text: t('paySettings.saved') }); client.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (err) => setMessage({ ok: false, text: errorOf(err, t('admin.actions.error')) }),
  })
  const test = useMutation({
    mutationFn: async () => (await api.post('/finance/payment-settings/test')).data,
    onSuccess: (d) => setMessage({ ok: d.ok, text: d.message }),
    onError: (err) => setMessage({ ok: false, text: errorOf(err, t('admin.actions.error')) }),
  })
  return (
    <form className="space-y-3 rounded-lg border bg-white p-4" onSubmit={(e) => { e.preventDefault(); setMessage(null); save.mutate() }} aria-label={t('paySettings.title')}>
      <div>
        <h2 className="font-bold">{t('paySettings.title')}</h2>
        <p className="text-sm text-slate-600">{t('paySettings.help')}</p>
        {settings.using_platform_account && !settings.enabled && <p className="mt-1 text-sm text-amber-800">{t('paySettings.platformAccount')}</p>}
      </div>
      {message && <p role="status" className={`rounded p-2 text-sm ${message.ok ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'}`}>{message.text}</p>}
      <div className="grid gap-3 sm:grid-cols-3">
        <label className="block text-sm">{t('paySettings.profileId')}<input required dir="ltr" className="mt-1 w-full rounded border p-2" value={form.profile_id} onChange={set('profile_id')} /></label>
        <label className="block text-sm">{t('paySettings.serverKey')}
          <input type="password" autoComplete="new-password" dir="ltr" className="mt-1 w-full rounded border p-2" placeholder={settings.server_key_set ? `${settings.server_key_hint} ${t('paySettings.keepKey')}` : ''} value={form.server_key} onChange={set('server_key')} />
        </label>
        <label className="block text-sm">{t('paySettings.region')}
          <select className="mt-1 w-full rounded border p-2" value={form.base_url} onChange={set('base_url')}>
            {Object.entries(settings.regions).map(([k, url]) => <option key={k} value={url}>{t(`paySettings.regions.${k}`)}</option>)}
          </select>
        </label>
      </div>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.enabled} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} />{t('paySettings.enabled')}</label>
      <p className="text-xs text-slate-500">{t('paySettings.callback')}: <bdi dir="ltr" className="break-all">{settings.callback_url}</bdi></p>
      <div className="flex flex-wrap gap-2">
        <button type="submit" disabled={save.isPending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        <button type="button" disabled={test.isPending || !settings.server_key_set} className="rounded border px-4 py-1.5 text-brand disabled:opacity-50" onClick={() => { setMessage(null); test.mutate() }}>{test.isPending ? t('paySettings.testing') : t('paySettings.test')}</button>
      </div>
    </form>
  )
}

export default function PaymentSettingsCard() {
  const { data } = useQuery({ queryKey: ['finance', 'payment-settings'], retry: false, queryFn: async () => (await api.get('/finance/payment-settings')).data.data })
  if (!data?.regions) return null
  return <Form key={`${data.profile_id}-${data.server_key_hint}-${data.enabled}`} settings={data} />
}
