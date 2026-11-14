import { useTranslation } from 'react-i18next'
import Modal from '../../../components/shared/Modal'
import { useSchool } from '../../../tenant/useSchool'
import Money from './Money'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// A receipt the accountant prints (the browser's print dialog; only the receipt is printed).
export default function ReceiptDialog({ payment, fee, student, currency, onClose }) {
  const { t, i18n } = useTranslation()
  const { data: school } = useSchool()
  const name = student[`name_${i18n.language}`] || student.name_ar
  const rows = [['receiptNo', <span key="no" dir="ltr">{payment.receipt}</span>], ['date', <span key="date" dir="ltr">{payment.paid_on}</span>], ['payer', payment.payer_name ?? '—'], ['student', name], ['for', fee.description],
    ['amount', <Money key="amount" value={payment.amount} currency={currency} />], ['method', t(`finance.methods.${payment.method}`)], ['reference', payment.reference ?? '—'], ['receivedBy', payment.received_by ?? '—']]

  return (
    <Modal open title={t('finance.receipt')} onClose={onClose}>
      <div className="space-y-3">
        <div className="print-area space-y-2 rounded border p-4">
          <h2 className="text-center text-lg font-bold">{school?.name}</h2>
          <p className="text-center text-sm text-slate-600">{t('finance.receipt')}</p>
          <dl className="space-y-1 text-sm">
            {rows.map(([k, v]) => <div key={k} className="flex justify-between gap-4"><dt className="text-slate-500">{t(`finance.receiptFields.${k}`)}</dt><dd className="font-medium">{v}</dd></div>)}
          </dl>
        </div>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('homework.close')}</button>
          <InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={() => window.print()}>{t('finance.print')}</InteractiveHoverButton>
        </div>
      </div>
    </Modal>
  )
}
