import { useTranslation } from 'react-i18next'
import Modal from './Modal'

export default function ConfirmDialog({ open, message, onConfirm, onCancel, confirmLabel, danger = true }) {
  const { t } = useTranslation()
  return (
    <Modal open={open} title={message} onClose={onCancel}>
      <div className="flex justify-end gap-2">
        <button type="button" className="rounded border px-3 py-1.5" onClick={onCancel}>{t('admin.actions.cancel')}</button>
        <button type="button" className={`rounded px-3 py-1.5 text-white ${danger ? 'bg-red-600' : 'bg-brand'}`} onClick={onConfirm}>
          {confirmLabel ?? t('admin.actions.delete')}
        </button>
      </div>
    </Modal>
  )
}
