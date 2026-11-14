import { useEffect, useState } from 'react'
import Cropper from 'react-easy-crop'
import { useTranslation } from 'react-i18next'
import Modal from '../../components/shared/Modal'
import cropImage from './cropImage'

// Lets the person move and zoom the picture inside a round frame and choose exactly what is kept; onSave gets the cropped file.
export default function PhotoCropper({ file, busy, onSave, onCancel }) {
  const { t } = useTranslation()
  const [src, setSrc] = useState(null)
  const [crop, setCrop] = useState({ x: 0, y: 0 })
  const [zoom, setZoom] = useState(1)
  const [area, setArea] = useState(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    if (!file) return undefined
    const url = URL.createObjectURL(file)
    setSrc(url)
    setCrop({ x: 0, y: 0 })
    setZoom(1)
    setArea(null)
    setFailed(false)
    return () => URL.revokeObjectURL(url)
  }, [file])

  const save = async () => {
    try { onSave(await cropImage(src, area)) } catch { setFailed(true) }
  }

  return (
    <Modal open={!!file} title={t('profile.cropTitle')} onClose={onCancel}>
      <p className="mb-3 text-sm text-slate-600">{t('profile.cropHelp')}</p>
      {/* dir="ltr": the cropper maths is in screen coordinates, whatever the page direction */}
      <div dir="ltr" className="relative h-72 w-full overflow-hidden rounded-lg bg-slate-900">
        {src && <Cropper image={src} crop={crop} zoom={zoom} aspect={1} cropShape="round" showGrid={false} onCropChange={setCrop} onZoomChange={setZoom} onCropComplete={(_, pixels) => setArea(pixels)} />}
      </div>
      <label className="mt-4 flex items-center gap-3 text-sm font-semibold text-deep-harbor">
        {t('profile.zoom')}
        <input type="range" min={1} max={3} step={0.01} value={zoom} onChange={(e) => setZoom(Number(e.target.value))} className="flex-1 accent-brand" />
      </label>
      {failed && <p role="alert" className="mt-3 rounded bg-red-50 p-2 text-sm text-red-700">{t('profile.cropFailed')}</p>}
      <div className="mt-5 flex justify-end gap-2">
        <button type="button" className="rounded-full border px-4 py-2 font-semibold" onClick={onCancel}>{t('profile.cancel')}</button>
        <button type="button" disabled={!area || busy} className="rounded-full bg-brand px-4 py-2 font-semibold text-white disabled:opacity-60" onClick={save}>{t('profile.savePhoto')}</button>
      </div>
    </Modal>
  )
}
