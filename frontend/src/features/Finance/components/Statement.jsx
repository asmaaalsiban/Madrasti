import { useTranslation } from 'react-i18next'
import Money from './Money'
import StatusBadge from './StatusBadge'

// A student's statement: totals, then every fee with its installments and payments. `actions` adds buttons (accountant).
export default function Statement({ data, onPay = null, onReceipt = null, onVoid = null, onDeleteFee = null, onTransport = null, onDiscount = null, onPayOnline = null }) {
  const { t } = useTranslation()
  const c = data.currency
  const totals = [['net', data.totals.net], ['paid', data.totals.paid], ...(Number(data.totals.carried) > 0 ? [['carried', data.totals.carried]] : []), ['remaining', data.totals.remaining], ['overdue', data.totals.overdue]]

  return (
    <div className="space-y-4">
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {totals.map(([key, value]) => (
          <div key={key} className={`rounded-lg border bg-white p-3 ${key === 'overdue' && Number(value) > 0 ? 'border-red-300' : ''}`}>
            <dt className="text-xs text-slate-500">{t(`finance.totals.${key}`)}</dt>
            <dd className="font-bold"><Money value={value} currency={c} /></dd>
          </div>
        ))}
      </dl>
      {data.fees.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('finance.noFees')}</p>}
      {data.fees.map((fee) => (
        <section key={fee.id} className="space-y-2 rounded-lg border bg-white p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="font-bold">{fee.description}</h2>
            <span className="text-sm text-slate-600">
              <Money value={fee.total} currency={c} />{Number(fee.discount) > 0 && <> − <Money value={fee.discount} currency={c} /> ({t('finance.discount')})</>}
            </span>
          </div>
          {fee.items?.length > 0 && (
            <ul className="space-y-0.5 text-sm" aria-label={t('finance.items')}>
              {fee.items.map((item) => (
                <li key={item.id} className="flex flex-wrap items-center justify-between gap-2">
                  <span>{item.kind === 'discount' ? t('finance.discount') : item.description}</span>
                  <span className="flex items-center gap-2">
                    <Money value={item.amount} currency={c} />
                    {onTransport && item.kind === 'transport' && <button type="button" className="text-xs text-brand underline" onClick={() => onTransport(fee, item)}>{t('finance.changeTransport')}</button>}
                  </span>
                </li>
              ))}
            </ul>
          )}
          {/* On a phone each installment is a small card (a six-column table does not fit); from a tablet up it is a table. */}
          <ul className="space-y-2 sm:hidden" aria-label={t('finance.installments')}>
            {fee.installments.map((i) => (
              <li key={i.id} className="space-y-1 rounded-lg border p-3 text-sm">
                <div className="flex items-center justify-between gap-2"><b>#{i.number} · <span dir="ltr">{i.due_date}</span></b><StatusBadge status={i.status} /></div>
                <div className="flex items-center justify-between gap-2"><span className="text-slate-600">{t('finance.cols.amount')}</span><Money value={i.amount} currency={c} /></div>
                <div className="flex items-center justify-between gap-2"><span className="text-slate-600">{t('finance.cols.paid')}</span><Money value={i.paid} currency={c} /></div>
                <div className="flex items-center justify-between gap-2"><span className="text-slate-600">{t('finance.cols.remaining')}</span><b><Money value={i.remaining} currency={c} /></b></div>
              </li>
            ))}
          </ul>
          <div className="hidden overflow-x-auto sm:block">
            <table className="w-full text-start text-sm">
              <thead className="bg-slate-50"><tr>{['no', 'dueDate', 'amount', 'paid', 'remaining', 'status'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`finance.cols.${k}`)}</th>)}</tr></thead>
              <tbody>
                {fee.installments.map((i) => (
                  <tr key={i.id} className="border-t">
                    <td className="p-2">{i.number}</td>
                    <td className="p-2" dir="ltr">{i.due_date}</td>
                    <td className="p-2"><Money value={i.amount} currency={c} /></td>
                    <td className="p-2"><Money value={i.paid} currency={c} /></td>
                    <td className="p-2"><Money value={i.remaining} currency={c} /></td>
                    <td className="p-2"><StatusBadge status={i.status} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {fee.payments.length > 0 && (
            <div>
              <h3 className="text-sm font-medium">{t('finance.payments')}</h3>
              <ul className="divide-y text-sm">
                {fee.payments.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                    <span><span dir="ltr">{p.paid_on}</span> · <Money value={p.amount} currency={c} /> · {t(`finance.methods.${p.method}`)} · <span dir="ltr">{p.receipt}</span>{p.payer_name && <> · {t('finance.paidBy', { name: p.payer_name })}</>}</span>
                    <span className="flex gap-2">
                      {onReceipt && p.method !== 'carry_forward' && <button type="button" className="text-brand underline" onClick={() => onReceipt(p, fee)}>{t('finance.receipt')}</button>}
                      {onVoid && p.method !== 'carry_forward' && <button type="button" className="text-red-700 underline" onClick={() => onVoid(p)}>{t('finance.voidPayment')}</button>}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {(onPay || onDeleteFee || onDiscount || onPayOnline) && (
            <div className="flex gap-2 pt-1">
              {onPayOnline && Number(fee.remaining) > 0 && <button type="button" className="rounded bg-brand px-3 py-1 text-sm text-white" onClick={() => onPayOnline(fee)}>{t('finance.payOnline')}</button>}
              {onPay && Number(fee.remaining) > 0 && <button type="button" className="rounded bg-brand px-3 py-1 text-sm text-white" onClick={() => onPay(fee)}>{t('finance.recordPayment')}</button>}
              {onDiscount && fee.kind !== 'transport' && <button type="button" className="rounded border px-3 py-1 text-sm" onClick={() => onDiscount(fee)}>{t('finance.changeDiscount')}</button>}
              {onDeleteFee && fee.payments.length === 0 && <button type="button" className="rounded border border-red-300 px-3 py-1 text-sm text-red-700" onClick={() => onDeleteFee(fee)}>{t('finance.deleteFee')}</button>}
            </div>
          )}
        </section>
      ))}
    </div>
  )
}
