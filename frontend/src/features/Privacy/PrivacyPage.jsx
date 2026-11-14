import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Download } from 'lucide-react'
import { api } from '../../api/client'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const STYLE = { pending: 'bg-amber-100 text-amber-800', done: 'bg-green-100 text-green-800', refused: 'bg-red-100 text-red-700' }

// Saves the file the server sends (it needs the sign-in token, so a plain link would not work).
async function download(params, name) {
  const res = await api.get('/privacy/export', { params, responseType: 'blob' })
  const url = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10000)
}

// "My data": download a copy of my data (and of each of my children's), and ask the school to delete or correct it.
export default function PrivacyPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ type: 'deletion', student_id: '', message: '' })
  const [message, setMessage] = useState(null)
  const { data } = useQuery({ queryKey: ['privacy', 'requests'], queryFn: async () => (await api.get('/privacy/requests')).data })
  const children = data?.children ?? []
  const nameOf = (c) => c[`name_${i18n.language}`] || c.name_ar
  const consent = useMutation({
    mutationFn: ({ id, allowed }) => api.put('/privacy/photo-consent', { student_id: id, allowed }),
    onSuccess: () => { setMessage({ ok: true, text: t('privacy.photo.saved') }); client.invalidateQueries({ queryKey: ['privacy'] }) },
    onError: (err) => setMessage({ ok: false, text: errorOf(err, t('admin.actions.error')) }),
  })
  const get = useMutation({ mutationFn: ({ params, name }) => download(params, name), onError: (err) => setMessage({ ok: false, text: errorOf(err, t('admin.actions.error')) }) })
  const ask = useMutation({
    mutationFn: () => api.post('/privacy/requests', { type: form.type, student_id: form.student_id ? Number(form.student_id) : null, message: form.message || null }),
    onSuccess: () => { setMessage({ ok: true, text: t('privacy.asked') }); setForm({ ...form, message: '' }); client.invalidateQueries({ queryKey: ['privacy'] }) },
    onError: (err) => setMessage({ ok: false, text: errorOf(err, t('admin.actions.error')) }),
  })
  return (
    <section className="space-y-5">
      <h1>{t('privacy.title')}</h1>
      <p className="text-sm text-slate-600">{t('privacy.help')} <Link to="/privacy" className="text-brand underline">{t('legal.privacy.title')}</Link></p>
      {message && <p role="status" className={`rounded p-2 text-sm ${message.ok ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'}`}>{message.text}</p>}

      {children.length > 0 && (
        <div className="space-y-2 rounded-lg border bg-white p-4">
          <h2 className="text-lg">{t('privacy.photo.title')}</h2>
          <p className="text-sm text-slate-600">{t('privacy.photo.help')}</p>
          <ul className="space-y-2">
            {children.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 rounded border p-2">
                <span><bdi className="font-medium">{nameOf(c)}</bdi> · <span className={c.photo_consent === true ? 'text-green-700' : c.photo_consent === false ? 'text-red-700' : 'text-amber-700'}>{t(`privacy.photo.state.${c.photo_consent === true ? 'yes' : c.photo_consent === false ? 'no' : 'unknown'}`)}</span></span>
                <span className="flex gap-2">
                  <button type="button" aria-label={t('privacy.photo.allowFor', { name: nameOf(c) })} aria-pressed={c.photo_consent === true} className={`rounded border px-3 py-1 text-sm ${c.photo_consent === true ? 'bg-green-700 text-white' : 'text-green-800'}`} onClick={() => consent.mutate({ id: c.id, allowed: true })}>{t('privacy.photo.allow')}</button>
                  <button type="button" aria-label={t('privacy.photo.denyFor', { name: nameOf(c) })} aria-pressed={c.photo_consent === false} className={`rounded border px-3 py-1 text-sm ${c.photo_consent === false ? 'bg-red-700 text-white' : 'text-red-800'}`} onClick={() => consent.mutate({ id: c.id, allowed: false })}>{t('privacy.photo.deny')}</button>
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="space-y-2 rounded-lg border bg-white p-4">
        <h2 className="text-lg">{t('privacy.download')}</h2>
        <p className="text-sm text-slate-600">{t('privacy.downloadHelp')}</p>
        <div className="flex flex-wrap gap-2">
          <InteractiveHoverButton type="button" className="px-4 py-2" onClick={() => get.mutate({ params: {}, name: 'my-data.json' })}><Download size={16} aria-hidden />{t('privacy.downloadMine')}</InteractiveHoverButton>
          {children.map((c) => (
            <button key={c.id} type="button" className="inline-flex items-center gap-2 rounded border px-4 py-2 text-brand" onClick={() => get.mutate({ params: { student_id: c.id }, name: `data-${c.id}.json` })}><Download size={16} aria-hidden />{t('privacy.downloadChild', { name: nameOf(c) })}</button>
          ))}
        </div>
      </div>

      <form className="space-y-3 rounded-lg border bg-white p-4" onSubmit={(e) => { e.preventDefault(); setMessage(null); ask.mutate() }}>
        <h2 className="text-lg">{t('privacy.request')}</h2>
        <p className="text-sm text-slate-600">{t('privacy.requestHelp')}</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">{t('privacy.what')}
            <select className="mt-1 w-full rounded border p-2" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
              {['deletion', 'correction'].map((k) => <option key={k} value={k}>{t(`privacy.types.${k}`)}</option>)}
            </select>
          </label>
          {children.length > 0 && (
            <label className="block text-sm">{t('privacy.about')}
              <select className="mt-1 w-full rounded border p-2" value={form.student_id} onChange={(e) => setForm({ ...form, student_id: e.target.value })}>
                <option value="">{t('privacy.aboutMe')}</option>
                {children.map((c) => <option key={c.id} value={c.id}>{nameOf(c)}</option>)}
              </select>
            </label>
          )}
        </div>
        <label className="block text-sm">{t('privacy.details')}<textarea rows={3} maxLength={1000} className="mt-1 w-full rounded border p-2" value={form.message} onChange={(e) => setForm({ ...form, message: e.target.value })} /></label>
        <InteractiveHoverButton type="submit" disabled={ask.isPending} className="px-4 py-2 disabled:opacity-50">{t('privacy.send')}</InteractiveHoverButton>
      </form>

      <div className="space-y-2">
        <h2 className="text-lg">{t('privacy.myRequests')}</h2>
        {(data?.data ?? []).length === 0 ? <p className="text-sm text-slate-500">{t('privacy.none')}</p> : (
          <ul className="space-y-2">
            {data.data.map((r) => (
              <li key={r.id} className="space-y-1 rounded-lg border bg-white p-3">
                <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-medium">{t(`privacy.types.${r.type}`)}</span><span className={`rounded-full px-2 py-0.5 text-xs ${STYLE[r.status]}`}>{t(`privacy.status.${r.status}`)}</span></div>
                {r.message && <p className="text-sm text-slate-600">{r.message}</p>}
                {r.answer && <p className="rounded bg-slate-50 p-2 text-sm"><b>{t('privacy.answer')}:</b> {r.answer}</p>}
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}
