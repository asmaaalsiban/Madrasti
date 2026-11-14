import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { AttachmentsField } from '../../../components/shared/Attachments'
import { uploadAttachments } from '../../../api/attachments'
import Money from './Money'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const firstError = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// A parent pays a fee online. Step 1: what and who. A real provider then takes them to its own page; the demo
// provider shows a card form here (no money moves, and the card is never kept).
export default function PayOnlineDialog({ studentId, fee, currency, payerDefault = '', demo = false, methods = ['card'], bank = null, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [step, setStep] = useState('details') // details | card | transfer | transferDone | done
  const [method, setMethod] = useState(methods.length === 1 ? methods[0] : null) // card | bank_transfer: the parent chooses when the school offers both
  const [proof, setProof] = useState({ transfer_reference: '', files: [] })
  const [form, setForm] = useState({ amount: fee.remaining, installment_id: '', payer_name: payerDefault ?? '' })
  const [card, setCard] = useState({ card_number: '', holder: '', expiry: '', cvc: '' })
  const [reference, setReference] = useState('')
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')

  const open = fee.installments.filter((i) => Number(i.remaining) > 0)
  const chooseInstallment = (e) => {
    const id = e.target.value
    const picked = open.find((i) => String(i.id) === id)
    setForm({ ...form, installment_id: id, amount: picked ? picked.remaining : fee.remaining })
  }

  const start = useMutation({
    mutationFn: async () => (await api.post(`/students/${studentId}/online-payments`, {
      student_fee_id: fee.id, amount: form.amount, installment_id: form.installment_id ? Number(form.installment_id) : null, payer_name: form.payer_name,
      return_url: `${window.location.origin}${window.location.pathname}`,
    })).data.data,
    onSuccess: (data) => {
      setError('')
      setReference(data.reference)
      if (data.mode === 'redirect' && data.url) window.location.assign(data.url)
      else setStep('card')
    },
    onError: (err) => setError(firstError(err, t('admin.actions.error'))),
  })
  // A bank transfer / CliQ: the parent says what they sent and attaches the proof; the school confirms it.
  const sendTransfer = useMutation({
    mutationFn: async () => {
      const data = (await api.post(`/students/${studentId}/online-payments`, {
        method: 'bank_transfer', student_fee_id: fee.id, amount: form.amount, installment_id: form.installment_id ? Number(form.installment_id) : null,
        payer_name: form.payer_name, transfer_reference: proof.transfer_reference,
      })).data.data
      await uploadAttachments('online_payment', data.id, proof.files)
      return data
    },
    onSuccess: () => { setError(''); setStep('transferDone'); client.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (err) => setError(firstError(err, t('admin.actions.error'))),
  })
  const pay = useMutation({
    mutationFn: async () => (await api.post(`/online-payments/${reference}/demo-confirm`, { ...card, card_number: card.card_number.replace(/\s/g, '') })).data.data,
    onSuccess: (data) => { setError(''); setResult(data); setStep('done'); client.invalidateQueries({ queryKey: ['finance'] }) },
    onError: (err) => setError(firstError(err, t('admin.actions.error'))),
  })
  const setC = (key) => (e) => setCard({ ...card, [key]: e.target.value })

  return (
    <Modal open title={t('finance.payOnlineFor', { fee: fee.description })} onClose={onClose}>
      {error && <p role="alert" className="mb-3 rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      {step === 'details' && (
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (method === 'bank_transfer') setStep('transfer'); else start.mutate() }}>
          {methods.length > 1 && (
            <fieldset className="space-y-1 text-sm">
              <legend className="mb-1">{t('finance.payBy')}</legend>
              {methods.map((m) => (
                <label key={m} className={`flex cursor-pointer items-center gap-2 rounded border p-2 ${method === m ? 'border-brand bg-sky-tint/40 font-bold' : ''}`}>
                  <input type="radio" name="pay-method" required checked={method === m} onChange={() => setMethod(m)} />{t(`finance.payMethod.${m}`)}
                </label>
              ))}
            </fieldset>
          )}
          <p className="text-sm text-slate-600">{t('finance.owed')}: <Money value={fee.remaining} currency={currency} /></p>
          <label className="block text-sm">{t('finance.fields.installment')}
            <select className="mt-1 w-full rounded border p-2" value={form.installment_id} onChange={chooseInstallment}>
              <option value="">{t('finance.wholeBalance')}</option>
              {open.map((i) => <option key={i.id} value={i.id}>{`#${i.number} · ${i.due_date} · ${i.remaining}`}</option>)}
            </select>
          </label>
          <label className="block text-sm">{t('finance.fields.amount')}<input required type="number" step="0.01" min="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></label>
          <label className="block text-sm">{t('finance.fields.payerName')}<input required className="mt-1 w-full rounded border p-2" value={form.payer_name} onChange={(e) => setForm({ ...form, payer_name: e.target.value })} /></label>
          <p className="text-xs text-slate-500">{t('finance.receiptInName')}</p>
          <div className="flex justify-end gap-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
            <InteractiveHoverButton type="submit" className="px-4 py-1.5 disabled:opacity-50" disabled={start.isPending}>{t('finance.continueToPay')}</InteractiveHoverButton>
          </div>
        </form>
      )}

      {step === 'transfer' && bank && (
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); sendTransfer.mutate() }}>
          <p className="text-sm">{t('finance.transfer.send')}: <b><Money value={form.amount} currency={currency} /></b></p>
          <ul className="space-y-2">
            {bank.accounts.map((a, i) => (
              <li key={i} className="rounded border bg-slate-50 p-2 text-sm">
                <p className="font-bold">{a.bank}{a.holder ? ` — ${a.holder}` : ''}</p>
                {a.iban && <p>IBAN: <bdi dir="ltr" className="select-all font-mono">{a.iban}</bdi></p>}
                {a.cliq && <p>CliQ: <bdi dir="ltr" className="select-all font-mono">{a.cliq}</bdi></p>}
              </li>
            ))}
          </ul>
          {bank.instructions && <p className="whitespace-pre-line rounded bg-amber-50 p-2 text-sm text-amber-900">{bank.instructions}</p>}
          <label className="block text-sm">{t('finance.transfer.reference')}<input required dir="ltr" maxLength={100} className="mt-1 w-full rounded border p-2" placeholder={t('finance.transfer.referenceHint')} value={proof.transfer_reference} onChange={(e) => setProof({ ...proof, transfer_reference: e.target.value })} /></label>
          <AttachmentsField files={proof.files} onChange={(files) => setProof({ ...proof, files })} label={t('finance.transfer.proof')} />
          <div className="flex justify-end gap-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={() => setStep('details')}>{t('finance.transfer.back')}</button>
            <InteractiveHoverButton type="submit" disabled={sendTransfer.isPending} className="px-4 py-1.5 disabled:opacity-50">{t('finance.transfer.submit')}</InteractiveHoverButton>
          </div>
        </form>
      )}

      {step === 'transferDone' && (
        <div className="space-y-3">
          <p role="status" className="rounded bg-green-50 p-3 text-green-800">{t('finance.transfer.sent')}</p>
          <div className="flex justify-end"><InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={onClose}>{t('finance.close')}</InteractiveHoverButton></div>
        </div>
      )}

      {step === 'card' && (
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); pay.mutate() }}>
          {demo && <p className="rounded bg-amber-50 p-2 text-xs text-amber-800">{t('finance.demoNotice')}</p>}
          <p className="text-sm">{t('finance.fields.amount')}: <b><Money value={form.amount} currency={currency} /></b></p>
          <label className="block text-sm">{t('finance.card.number')}<input required inputMode="numeric" autoComplete="cc-number" dir="ltr" maxLength={23} placeholder="4242 4242 4242 4242" className="mt-1 w-full rounded border p-2" value={card.card_number} onChange={setC('card_number')} /></label>
          <label className="block text-sm">{t('finance.card.holder')}<input required autoComplete="cc-name" className="mt-1 w-full rounded border p-2" value={card.holder} onChange={setC('holder')} /></label>
          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm">{t('finance.card.expiry')}<input required autoComplete="cc-exp" dir="ltr" placeholder="MM/YY" pattern="(0[1-9]|1[0-2])/\d{2}" maxLength={5} className="mt-1 w-full rounded border p-2" value={card.expiry} onChange={setC('expiry')} /></label>
            <label className="block text-sm">{t('finance.card.cvc')}<input required type="password" inputMode="numeric" autoComplete="cc-csc" dir="ltr" maxLength={4} className="mt-1 w-full rounded border p-2" value={card.cvc} onChange={setC('cvc')} /></label>
          </div>
          <div className="flex justify-end gap-2">
            <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
            <InteractiveHoverButton type="submit" className="px-4 py-1.5 disabled:opacity-50" disabled={pay.isPending}>{t('finance.card.pay', { amount: form.amount, currency })}</InteractiveHoverButton>
          </div>
        </form>
      )}

      {step === 'done' && result && (
        <div className="space-y-3">
          {result.status === 'paid' && <p role="status" className="rounded bg-green-50 p-3 text-green-800">{t('finance.online.paid', { receipt: result.receipt })}</p>}
          {result.status === 'failed' && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{t(`finance.online.failed.${result.failure_reason}`, { defaultValue: t('finance.online.failed.default') })}</p>}
          {result.status === 'review' && <p role="alert" className="rounded bg-amber-50 p-3 text-amber-800">{t('finance.online.review')}</p>}
          <div className="flex justify-end gap-2">
            {result.status === 'failed' && <button type="button" className="rounded border px-3 py-1.5" onClick={() => { setResult(null); setStep('details') }}>{t('finance.online.tryAgain')}</button>}
            <InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={onClose}>{t('finance.close')}</InteractiveHoverButton>
          </div>
        </div>
      )}
    </Modal>
  )
}
