import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ImagePlus, X } from 'lucide-react'
import { api } from '../../api/client'
import { useAuth } from '../Auth/AuthProvider'
import { InteractiveHoverButton } from '../../components/ui/interactive-hover-button'

const MAX = 6

// "Share something": text, up to six photos, and who it is for (a teacher: one of their classes; the principal: a class, a grade or the whole school).
export default function StoryComposer() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const client = useQueryClient()
  const input = useRef(null)
  const [body, setBody] = useState('')
  const [files, setFiles] = useState([])
  const [target, setTarget] = useState('') // 'classroom:3' | 'grade:2' | 'school'
  const [comments, setComments] = useState(true)
  const [error, setError] = useState('')
  const { data: targets } = useQuery({ queryKey: ['stories', 'targets'], queryFn: async () => (await api.get('/stories/targets')).data.data })
  const previews = useMemo(() => files.map((f) => URL.createObjectURL(f)), [files])
  useEffect(() => () => previews.forEach((u) => URL.revokeObjectURL(u)), [previews])
  const options = [
    ...(targets?.school ? [{ value: 'school', label: t('stories.wholeSchool') }] : []),
    ...(targets?.grades ?? []).map((g) => ({ value: `grade:${g.id}`, label: `${t('stories.gradeOf')} ${g[`name_${i18n.language}`] || g.name_ar}` })),
    ...(targets?.classrooms ?? []).map((c) => ({ value: `classroom:${c.id}`, label: c.name })),
  ]
  const chosen = target || options[0]?.value || ''
  // With photos, who in this group has not allowed photos of their child? (the school chooses: only a warning, or photos blocked)
  const [audience, audienceId] = chosen.split(':')
  const consent = useQuery({
    queryKey: ['stories', 'consent', chosen], enabled: files.length > 0 && Boolean(chosen),
    queryFn: async () => (await api.get('/stories/consent-check', { params: { audience, ...(audience === 'classroom' ? { classroom_id: audienceId } : {}), ...(audience === 'grade' ? { grade_level_id: audienceId } : {}) } })).data,
  })
  const missing = files.length > 0 && consent.data?.mode !== 'off' ? consent.data?.missing_count ?? 0 : 0
  const blocked = missing > 0 && consent.data?.mode === 'required'
  const send = useMutation({
    mutationFn: () => {
      const [audience, id] = chosen.split(':')
      const data = new FormData()
      data.append('audience', audience)
      if (audience === 'classroom') data.append('classroom_id', id)
      if (audience === 'grade') data.append('grade_level_id', id)
      if (body.trim()) data.append('body', body.trim())
      data.append('comments_enabled', comments ? '1' : '0')
      files.forEach((f) => data.append('images[]', f))
      return api.post('/stories', data, { headers: { 'Content-Type': 'multipart/form-data' } })
    },
    onSuccess: () => { setBody(''); setFiles([]); setError(''); client.invalidateQueries({ queryKey: ['stories'] }) },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || t('admin.actions.error')),
  })
  if (!targets || options.length === 0) return null
  const add = (e) => { setFiles([...files, ...Array.from(e.target.files)].slice(0, MAX)); e.target.value = '' }
  return (
    <form className="space-y-3 rounded-lg border bg-snow p-4" onSubmit={(e) => { e.preventDefault(); send.mutate() }} aria-label={t('stories.share')}>
      <div className="flex items-start gap-3">
        <span aria-hidden className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-deep-harbor text-lg font-bold text-white">{user?.name?.trim().charAt(0)}</span>
        <textarea aria-label={t('stories.whatsNew')} rows={3} maxLength={2000} className="min-w-0 flex-1 resize-none rounded-2xl border p-3" placeholder={t('stories.whatsNew')} value={body} onChange={(e) => setBody(e.target.value)} />
      </div>
      {previews.length > 0 && (
        <ul className="grid grid-cols-3 gap-2 sm:grid-cols-6">
          {previews.map((url, i) => (
            <li key={url} className="relative">
              <img src={url} alt={t('stories.newPhoto', { number: i + 1 })} className="h-20 w-full rounded-xl object-cover" />
              <button type="button" aria-label={t('stories.removePhoto', { number: i + 1 })} className="absolute -end-1 -top-1 grid h-6 w-6 place-items-center rounded-full bg-red-600 text-white" onClick={() => setFiles(files.filter((_, j) => j !== i))}><X size={13} /></button>
            </li>
          ))}
        </ul>
      )}
      <div className="flex flex-wrap items-center gap-2">
        <input ref={input} type="file" multiple accept="image/jpeg,image/png,image/webp" className="sr-only" aria-label={t('stories.addPhotos')} onChange={add} />
        <button type="button" disabled={files.length >= MAX} className="flex items-center gap-2 rounded border px-3 py-1.5 text-sm text-brand disabled:opacity-50" onClick={() => input.current.click()}><ImagePlus size={18} aria-hidden />{t('stories.addPhotos')} ({files.length}/{MAX})</button>
        <label className="flex items-center gap-2 text-sm">{t('stories.for')}
          <select className="rounded border p-1.5" value={chosen} onChange={(e) => setTarget(e.target.value)}>{options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
        </label>
        <label className="flex items-center gap-1.5 text-sm"><input type="checkbox" checked={comments} onChange={(e) => setComments(e.target.checked)} />{t('stories.allowComments')}</label>
        <InteractiveHoverButton type="submit" disabled={send.isPending || blocked || (!body.trim() && files.length === 0)} className="ms-auto px-6 py-2 disabled:opacity-50">{t('stories.post')}</InteractiveHoverButton>
      </div>
      {missing > 0 && (
        <p role={blocked ? 'alert' : 'status'} className={`rounded p-2 text-sm ${blocked ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-900'}`}>
          {t(blocked ? 'stories.consentBlocked' : 'stories.consentWarn', { count: missing })}{' '}
          <bdi>{(consent.data.missing ?? []).slice(0, 8).map((s) => s[`name_${i18n.language}`] || s.name_ar).join('، ')}{consent.data.missing_count > 8 ? '…' : ''}</bdi>
        </p>
      )}
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
    </form>
  )
}
