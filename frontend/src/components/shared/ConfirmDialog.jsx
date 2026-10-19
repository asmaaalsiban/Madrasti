import { useTranslation } from 'react-i18next'
import Modal from './Modal'

export default function ConfirmDialog({ open, message, onConfirm, onCancel }) {
  const { t } = useTranslation()
  return (
    <Modal open={open} title={message} onClose={onCancel}>
      <div className="flex justify-end gap-2">
        <button type="button" className="rounded border px-3 py-1.5" onClick={onCancel}>{t('admin.actions.cancel')}</button>
        <button type="button" className="rounded bg-red-600 px-3 py-1.5 text-white" onClick={onConfirm}>{t('admin.actions.delete')}</button>
      </div>
    </Modal>
  )
}
