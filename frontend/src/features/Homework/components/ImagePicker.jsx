import { useEffect, useMemo, useRef } from 'react'
import { useTranslation } from 'react-i18next'

// A clear "add a photo" button (camera icon) instead of the bare browser file field. On a phone it opens the camera or gallery.
// children: what to show when there is already a saved photo (e.g. <HomeworkImage />); onRemoveSaved: drops that saved photo.
export default function ImagePicker({ file, onChange, saved = false, onRemoveSaved = null, children = null }) {
  const { t } = useTranslation()
  const input = useRef(null)
  const preview = useMemo(() => (file ? URL.createObjectURL(file) : null), [file])
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview) }, [preview])

  return (
    <div className="space-y-2">
      <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" aria-label={t('homework.fields.image')} onChange={(e) => onChange(e.target.files[0] ?? null)} />
      <button type="button" className="flex w-full items-center justify-center gap-2 rounded-lg border-2 border-dashed border-brand/50 p-4 text-brand hover:bg-brand/5" onClick={() => input.current.click()}>
        <span aria-hidden="true" className="text-2xl">📷</span>
        <span>{file || saved ? t('homework.changePhoto') : t('homework.addPhoto')}</span>
      </button>
      {preview && (
        <div className="space-y-1">
          <img src={preview} alt={t('homework.imageAlt')} className="max-h-48 rounded border" />
          <button type="button" className="text-sm text-red-700 underline" onClick={() => onChange(null)}>{t('homework.removeImage')}</button>
        </div>
      )}
      {!preview && saved && (
        <div>
          {children}
          {onRemoveSaved && <button type="button" className="text-sm text-red-700 underline" onClick={onRemoveSaved}>{t('homework.removeImage')}</button>}
        </div>
      )}
    </div>
  )
}
