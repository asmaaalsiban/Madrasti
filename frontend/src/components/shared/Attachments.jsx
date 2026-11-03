import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { FileText, Paperclip, X } from 'lucide-react'
import { api } from '../../api/client'
import { openAttachment, uploadAttachments } from '../../api/attachments'
import AuthImage from './AuthImage'

export const ACCEPT = '.jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,image/*'
const MAX = 10

const sizeText = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`)

// Saved files: images as small pictures (click to open), other files as a named button (click to download).
export function AttachmentList({ items = [], onRemove }) {
  const { t } = useTranslation()
  if (items.length === 0) return null
  return (
    <ul className="flex flex-wrap gap-2" aria-label={t('attachments.title')}>
      {items.map((f) => (
        <li key={f.id} className="flex items-center gap-1 rounded-lg border bg-white p-1">
          {f.is_image ? (
            <button type="button" className="block" aria-label={t('attachments.open', { name: f.name })} onClick={() => openAttachment(f)}>
              <AuthImage path={`/attachments/${f.id}`} alt={f.name} className="h-16 w-16 rounded object-cover" />
            </button>
          ) : (
            <button type="button" className="flex items-center gap-2 px-2 py-1 text-sm text-brand" onClick={() => openAttachment(f)}>
              <FileText size={18} aria-hidden /><bdi className="max-w-[12rem] truncate">{f.name}</bdi><span className="text-xs text-slate-500">{sizeText(f.size)}</span>
            </button>
          )}
          {onRemove && <button type="button" aria-label={t('attachments.remove', { name: f.name })} className="rounded p-1 text-red-700" onClick={() => onRemove(f)}><X size={16} aria-hidden /></button>}
        </li>
      ))}
    </ul>
  )
}

function Chosen({ file, onRemove }) {
  const { t } = useTranslation()
  const preview = useMemo(() => (file.type?.startsWith('image/') ? URL.createObjectURL(file) : null), [file])
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview) }, [preview])
  return (
    <li className="flex items-center gap-1 rounded-lg border bg-sky-tint/40 p-1">
      {preview ? <img src={preview} alt={file.name} className="h-16 w-16 rounded object-cover" /> : <span className="flex items-center gap-2 px-2 py-1 text-sm"><FileText size={18} aria-hidden /><bdi className="max-w-[12rem] truncate">{file.name}</bdi><span className="text-xs text-slate-500">{sizeText(file.size)}</span></span>}
      <button type="button" aria-label={t('attachments.remove', { name: file.name })} className="rounded p-1 text-red-700" onClick={onRemove}><X size={16} aria-hidden /></button>
    </li>
  )
}

// A form field: files already saved (can be taken off) and new files chosen now (uploaded by the form after it saves).
export function AttachmentsField({ files, onChange, saved = [], onRemoveSaved, label }) {
  const { t } = useTranslation()
  const input = useRef(null)
  const room = MAX - saved.length - files.length
  const pick = (e) => { onChange([...files, ...Array.from(e.target.files ?? [])].slice(0, MAX - saved.length)); e.target.value = '' }
  return (
    <div className="space-y-2 text-sm">
      <span className="block">{label ?? t('attachments.title')}</span>
      <AttachmentList items={saved} onRemove={onRemoveSaved} />
      {files.length > 0 && <ul className="flex flex-wrap gap-2">{files.map((f, i) => <Chosen key={`${f.name}-${i}`} file={f} onRemove={() => onChange(files.filter((_, j) => j !== i))} />)}</ul>}
      <input ref={input} type="file" multiple accept={ACCEPT} className="sr-only" aria-label={t('attachments.choose')} onChange={pick} tabIndex={-1} />
      <button type="button" disabled={room <= 0} className="inline-flex items-center gap-2 rounded border px-3 py-1.5 text-brand disabled:opacity-50" onClick={() => input.current?.click()}><Paperclip size={16} aria-hidden />{t('attachments.add')}</button>
      <p className="text-xs text-slate-500">{t('attachments.hint', { max: MAX })}</p>
    </div>
  )
}

// Files of something that already exists (a task's hand-in): picking uploads at once, the cross removes at once.
export function LiveAttachments({ ownerType, ownerId, items, label, invalidate = ['tasks'] }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const input = useRef(null)
  const [error, setError] = useState('')
  const done = () => client.invalidateQueries({ queryKey: invalidate })
  const fail = (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || t('admin.actions.error'))
  const add = useMutation({ mutationFn: (files) => uploadAttachments(ownerType, ownerId, files), onSuccess: () => { setError(''); done() }, onError: fail })
  const remove = useMutation({ mutationFn: (f) => api.delete(`/attachments/${f.id}`), onSuccess: done, onError: fail })
  return (
    <div className="space-y-2 text-sm">
      <span className="block">{label ?? t('attachments.title')}</span>
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-red-700">{error}</p>}
      <AttachmentList items={items} onRemove={(f) => remove.mutate(f)} />
      <input ref={input} type="file" multiple accept={ACCEPT} className="sr-only" aria-label={t('attachments.choose')} tabIndex={-1} onChange={(e) => { const files = Array.from(e.target.files ?? []); e.target.value = ''; if (files.length) add.mutate(files) }} />
      <button type="button" disabled={add.isPending || items.length >= MAX} className="inline-flex items-center gap-2 rounded border px-3 py-1.5 text-brand disabled:opacity-50" onClick={() => input.current?.click()}><Paperclip size={16} aria-hidden />{add.isPending ? t('attachments.uploading') : t('attachments.add')}</button>
    </div>
  )
}
