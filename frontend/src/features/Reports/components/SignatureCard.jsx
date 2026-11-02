import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

const CHECKER = { backgroundImage: 'linear-gradient(45deg,#e5e7eb 25%,transparent 25%),linear-gradient(-45deg,#e5e7eb 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#e5e7eb 75%),linear-gradient(-45deg,transparent 75%,#e5e7eb 75%)', backgroundSize: '16px 16px', backgroundPosition: '0 0,0 8px,8px -8px,-8px 0' }
const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// One saved signature: how it will print (on a checkerboard, so you can see the transparent background), a strength
// slider to clean more or less of the paper, its title and name, show/hide, and delete.
export default function SignatureCard({ signature, canEditTitle }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [strength, setStrength] = useState(signature.strength)
  const [title, setTitle] = useState(signature.title ?? '')
  const [name, setName] = useState(signature.signer_name ?? '')
  const [error, setError] = useState('')
  const refresh = () => client.invalidateQueries({ queryKey: ['signatures'] })
  const fail = (err) => setError(errorOf(err, t('admin.actions.error')))

  const reprocess = useMutation({ mutationFn: (v) => api.post(`/signatures/${signature.id}/reprocess`, { strength: v }), onSuccess: () => { setError(''); refresh() }, onError: fail })
  const save = useMutation({ mutationFn: (body) => api.put(`/signatures/${signature.id}`, body), onSuccess: () => { setError(''); refresh() }, onError: fail })
  const remove = useMutation({ mutationFn: () => api.delete(`/signatures/${signature.id}`), onSuccess: refresh })

  return (
    <li className="space-y-3 rounded-lg border bg-white p-4">
      <div className="flex min-h-24 items-center justify-center rounded border p-3" style={CHECKER}>
        {signature.image ? <img src={signature.image} alt={t('signatures.preview', { name: signature.signer_name || signature.title || '' })} className="max-h-24 max-w-full object-contain" /> : <span className="text-sm text-slate-500">{t('signatures.noImage')}</span>}
      </div>

      <label className="block text-sm">{t('signatures.strength')}: <b dir="ltr">{strength}</b>
        <input type="range" min="0" max="100" aria-label={t('signatures.strength')} className="mt-1 w-full" value={strength} onChange={(e) => setStrength(Number(e.target.value))}
          onPointerUp={() => strength !== signature.strength && reprocess.mutate(strength)} onKeyUp={() => strength !== signature.strength && reprocess.mutate(strength)} />
        <span className="flex justify-between text-xs text-slate-500"><span>{t('signatures.keepMore')}</span><span>{t('signatures.removeMore')}</span></span>
      </label>

      <div className="grid gap-2 sm:grid-cols-2">
        {canEditTitle && <label className="block text-sm">{t('signatures.title')}<input className="mt-1 w-full rounded border p-2" value={title} onChange={(e) => setTitle(e.target.value)} onBlur={() => title.trim() && title !== signature.title && save.mutate({ title })} /></label>}
        <label className="block text-sm">{t('signatures.signerName')}<input className="mt-1 w-full rounded border p-2" value={name} onChange={(e) => setName(e.target.value)} onBlur={() => name !== (signature.signer_name ?? '') && save.mutate({ signer_name: name })} /></label>
      </div>

      {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={signature.is_active} onChange={(e) => save.mutate({ is_active: e.target.checked })} />{t('signatures.show')}</label>
        <button type="button" className="rounded border border-red-300 px-3 py-1 text-sm text-red-700" onClick={() => remove.mutate()}>{t('admin.actions.delete')}</button>
      </div>
    </li>
  )
}
