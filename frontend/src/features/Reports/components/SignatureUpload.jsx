import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// Add (or replace) a signature from a photo or scan. The server removes the paper; the strength can be adjusted after.
export default function SignatureUpload({ scope, userId = null, withTitle = false, label }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const input = useRef(null)
  const [file, setFile] = useState(null)
  const [title, setTitle] = useState('')
  const [name, setName] = useState('')
  const [error, setError] = useState('')

  const send = useMutation({
    mutationFn: () => {
      const body = new FormData()
      body.append('image', file)
      body.append('scope', scope)
      if (userId) body.append('user_id', userId)
      if (withTitle) body.append('title', title)
      if (name) body.append('signer_name', name)
      return api.post('/signatures', body)
    },
    onSuccess: () => { setError(''); setFile(null); setTitle(''); setName(''); if (input.current) input.current.value = ''; client.invalidateQueries({ queryKey: ['signatures'] }) },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || t('admin.actions.error')),
  })

  return (
    <form className="space-y-3 rounded-lg border border-dashed border-brand bg-blue-50/40 p-4" onSubmit={(e) => { e.preventDefault(); send.mutate() }}>
      <p className="font-medium">{label}</p>
      {withTitle && <label className="block text-sm">{t('signatures.title')}<input required className="mt-1 w-full rounded border p-2" placeholder={t('signatures.titleHint')} value={title} onChange={(e) => setTitle(e.target.value)} /></label>}
      <label className="block text-sm">{t('signatures.signerName')}<input className="mt-1 w-full rounded border p-2" value={name} onChange={(e) => setName(e.target.value)} /></label>
      <input ref={input} type="file" className="sr-only" tabIndex={-1} aria-label={t('signatures.file')} accept="image/png,image/jpeg,image/webp" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setError('') }} />
      <button type="button" className="flex w-full items-center justify-center gap-2 rounded border-2 border-dashed border-brand bg-white px-4 py-4 font-bold text-brand hover:bg-blue-50" onClick={() => input.current?.click()}>
        <span aria-hidden="true" className="text-xl">✍️</span>{file ? t('signatures.changeFile') : t('signatures.pickFile')}
      </button>
      {file && <p role="status" className="text-sm text-green-800">✔ {t('signatures.chosen', { name: file.name })}</p>}
      <p className="text-xs text-slate-500">{t('signatures.tips')}</p>
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      <button type="submit" disabled={!file || send.isPending} className="rounded bg-brand px-4 py-2 text-white disabled:opacity-50">{t('signatures.save')}</button>
    </form>
  )
}
