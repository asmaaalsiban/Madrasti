import { useEffect, useMemo, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import HomeworkImage from './HomeworkImage'

const MAX = 10

// A clear "add photos" button (camera icon). Every pick is ADDED to the photos already chosen or saved, never replacing them,
// so a student can send page 1 now and page 2 later. On a phone it opens the camera or gallery.
// files: photos chosen but not saved yet; saved: ids of photos already saved; onRemoveSaved(id) drops a saved one.
export default function ImagePicker({ files, onChange, saved = [], onRemoveSaved }) {
  const { t } = useTranslation()
  const input = useRef(null)
  const previews = useMemo(() => files.map((f) => URL.createObjectURL(f)), [files])
  useEffect(() => () => previews.forEach((u) => URL.revokeObjectURL(u)), [previews])
  const total = saved.length + files.length

  const add = (e) => {
    onChange([...files, ...Array.from(e.target.files)].slice(0, MAX - saved.length))
    e.target.value = ''
  }

  return (
    <div className="space-y-2">
      <input ref={input} type="file" multiple accept="image/jpeg,image/png,image/webp" className="sr-only" aria-label={t('homework.fields.image')} onChange={add} />
      <button type="button" disabled={total >= MAX} className="flex w-full items-center justify-center gap-2 rounded-lg border-2 border-dashed border-brand/50 p-4 text-brand hover:bg-brand/5 disabled:opacity-50" onClick={() => input.current.click()}>
        <span aria-hidden="true" className="text-2xl">📷</span>
        <span>{total > 0 ? t('homework.addAnotherPhoto') : t('homework.addPhoto')}</span>
      </button>
      <p className="text-xs text-slate-500">{t('homework.photoCount', { count: total, max: MAX })}</p>
      {(saved.length > 0 || files.length > 0) && (
        <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3">
          {saved.map((id, i) => (
            <li key={`s${id}`} className="space-y-1">
              <HomeworkImage fileId={id} label={t('homework.page', { number: i + 1 })} />
              <button type="button" className="text-sm text-red-700 underline" onClick={() => onRemoveSaved(id)}>{t('homework.removePhoto', { number: i + 1 })}</button>
            </li>
          ))}
          {previews.map((url, i) => (
            <li key={`n${url}`} className="space-y-1">
              <img src={url} alt={t('homework.newPage', { number: saved.length + i + 1 })} className="max-h-40 rounded border" />
              <button type="button" className="text-sm text-red-700 underline" onClick={() => onChange(files.filter((_, j) => j !== i))}>{t('homework.removePhoto', { number: saved.length + i + 1 })}</button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
