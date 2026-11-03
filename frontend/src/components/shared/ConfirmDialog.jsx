import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { CircleHelp, Trash2 } from 'lucide-react'
import Modal from './Modal'

// "Are you sure?": an icon, the question, what will happen (optional) and two clear buttons. Cancel has the focus, so Enter does not delete by accident.
export default function ConfirmDialog({ open, message, description, onConfirm, onCancel, confirmLabel, danger = true, busy = false }) {
  const { t } = useTranslation()
  const cancel = useRef(null)
  useEffect(() => { if (open) cancel.current?.focus() }, [open])
  const Icon = danger ? Trash2 : CircleHelp
  return (
    <Modal open={open} title={message} onClose={onCancel} hideTitle>
      <div className="space-y-4 text-center">
        <span aria-hidden className={`mx-auto grid h-16 w-16 place-items-center rounded-full ${danger ? 'bg-red-50 text-red-600' : 'bg-sky-tint text-deep-harbor'}`}><Icon size={30} /></span>
        <div className="space-y-1">
          <h2 className="text-lg font-extrabold text-deep-harbor">{message}</h2>
          {description && <p className="text-sm text-slate-600">{description}</p>}
        </div>
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-center">
          <button ref={cancel} type="button" className="rounded border px-6 py-2 font-semibold" onClick={onCancel}>{t('admin.actions.cancel')}</button>
          <button type="button" disabled={busy} className={`rounded px-6 py-2 font-bold text-white disabled:opacity-50 ${danger ? '!bg-red-600' : 'bg-brand'}`} style={danger ? { boxShadow: '0 5px 0 0 #fecaca' } : undefined} onClick={onConfirm}>
            {confirmLabel ?? t('admin.actions.delete')}
          </button>
        </div>
      </div>
    </Modal>
  )
}
