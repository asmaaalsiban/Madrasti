import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import Money from '../../Finance/components/Money'
import { useAuth } from '../../Auth/AuthProvider'
import { itemLabel } from '../itemLabel'
import { DateInput } from '../../../components/shared/DateInput'

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

function ItemDialog({ slip, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState({ kind: 'bonus', label: '', amount: '', category: '' })
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => api.post(`/payroll/payslips/${slip.id}/items`, { ...form, category: form.category || null }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['payroll'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  return (
    <Modal open title={t('payroll.addFor', { name: slip.user.name })} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <label className="block text-sm">{t('payroll.kind')}
          <select className="mt-1 w-full rounded border p-2" value={form.kind} onChange={set('kind')}>
            {['bonus', 'allowance', 'deduction'].map((k) => <option key={k} value={k}>{t(`payroll.kinds.${k}`)}</option>)}
          </select>
        </label>
        <label className="block text-sm">{t('payroll.category')}
          <select className="mt-1 w-full rounded border p-2" value={form.category} onChange={set('category')}><option value="">—</option>{['private_lessons', 'summer_club', 'club', 'other'].map((c) => <option key={c} value={c}>{t(`payroll.categories.${c}`)}</option>)}</select>
        </label>
        <label className="block text-sm">{t('payroll.label')}<input required className="mt-1 w-full rounded border p-2" placeholder={t('payroll.reasonHint')} value={form.label} onChange={set('label')} /></label>
        <label className="block text-sm">{t('payroll.amount')}<input required type="number" step="0.01" min="0.01" dir="ltr" className="mt-1 w-full rounded border p-2" value={form.amount} onChange={set('amount')} /></label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button>
        </div>
      </form>
    </Modal>
  )
}

// How one payslip is paid this month: the contract's default, changeable for this person and this month.
function PaymentCell({ slip, editable }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [details, setDetails] = useState(slip.payment_details ?? '')
  const save = useMutation({ mutationFn: (body) => api.put(`/payroll/payslips/${slip.id}/payment`, body), onSuccess: () => client.invalidateQueries({ queryKey: ['payroll'] }) })
  if (!editable) return <span>{slip.payment_method ? t(`finance.methods.${slip.payment_method}`) : '—'}{slip.payment_details ? <span className="block text-xs text-slate-500" dir="ltr">{slip.payment_details}</span> : null}</span>
  return (
    <span className="flex flex-col gap-1">
      <select aria-label={`${t('payroll.payMethod')} ${slip.user.name}`} className="rounded border p-1" value={slip.payment_method ?? ''} onChange={(e) => e.target.value && save.mutate({ payment_method: e.target.value, payment_details: details || null })}>
        <option value="">—</option>
        {['bank_transfer', 'cheque', 'cash'].map((m) => <option key={m} value={m}>{t(`finance.methods.${m}`)}</option>)}
      </select>
      <input aria-label={`${t('payroll.payDetails')} ${slip.user.name}`} dir="ltr" className="rounded border p-1 text-xs" placeholder={t('payroll.payDetailsHint')} value={details} onChange={(e) => setDetails(e.target.value)} onBlur={() => slip.payment_method && details !== (slip.payment_details ?? '') && save.mutate({ payment_method: slip.payment_method, payment_details: details || null })} />
    </span>
  )
}

// Gives one of the school's own rules to everyone on the payroll, or to one person.
function ApplyRuleDialog({ run, slip, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [id, setId] = useState('')
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const { data } = useQuery({ queryKey: ['payroll', 'templates'], queryFn: async () => (await api.get('/payroll/templates')).data })
  const save = useMutation({
    mutationFn: () => api.post(`/payroll/runs/${run.id}/apply-template`, { template_id: Number(id), ...(slip ? { payslip_ids: [slip.id] } : {}) }),
    onSuccess: (res) => { client.invalidateQueries({ queryKey: ['payroll'] }); setError(''); setResult(res.data) },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const rules = (data?.data ?? []).filter((r) => r.is_active)
  return (
    <Modal open title={slip ? t('payroll.applyRuleFor', { name: slip.user.name }) : t('payroll.applyRuleAll')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {result && <p role="status" className="rounded bg-green-50 p-2 text-sm text-green-800">{t('payroll.ruleApplied', { applied: result.applied, skipped: result.skipped })}</p>}
        <label className="block text-sm">{t('payroll.chooseRule')}
          <select required className="mt-1 w-full rounded border p-2" value={id} onChange={(e) => { setId(e.target.value); setResult(null) }}>
            <option value="">—</option>
            {rules.map((r) => <option key={r.id} value={r.id}>{r.name} ({t(`payroll.kinds.${r.kind}`)} {r.type === 'percent' ? `${Number(r.value)}%` : r.value})</option>)}
          </select>
        </label>
        {rules.length === 0 && <p className="text-sm text-slate-500">{t('payroll.noTemplates')}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('payroll.close')}</button>
          <button type="submit" disabled={save.isPending || !id} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('payroll.apply')}</button>
        </div>
      </form>
    </Modal>
  )
}

function PayDialog({ run, onClose }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [form, setForm] = useState(() => ({ method: '', paid_on: new Date().toISOString().slice(0, 10), reference: '' }))
  const [error, setError] = useState('')
  const unpaid = run.payslips.filter((s) => !s.paid_on)
  const missing = unpaid.filter((s) => !s.payment_method).length
  const save = useMutation({
    mutationFn: () => api.post(`/payroll/runs/${run.id}/pay`, { ...form, method: form.method || null, reference: form.reference || null }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['payroll'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  return (
    <Modal open title={t('payroll.payAll')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <ul className="rounded border bg-slate-50 p-2 text-sm">
          {['bank_transfer', 'cheque', 'cash'].map((m) => { const n = unpaid.filter((s) => s.payment_method === m).length; return n > 0 && <li key={m}>{t(`finance.methods.${m}`)}: {n}</li> })}
        </ul>
        {missing > 0 && (
          <label className="block text-sm">{t('payroll.methodForRest', { count: missing })}
            <select required className="mt-1 w-full rounded border p-2" value={form.method} onChange={set('method')}>
              <option value="">—</option>
              {['bank_transfer', 'cheque', 'cash'].map((m) => <option key={m} value={m}>{t(`finance.methods.${m}`)}</option>)}
            </select>
          </label>
        )}
        <label className="block text-sm">{t('payroll.paidOn')}<DateInput required className="mt-1 w-full rounded border p-2" value={form.paid_on} onChange={set('paid_on')} /></label>
        <label className="block text-sm">{t('payroll.reference')}<input className="mt-1 w-full rounded border p-2" value={form.reference} onChange={set('reference')} /></label>
        <div className="flex justify-end gap-2">
          <button type="button" className="rounded border px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</button>
          <button type="submit" disabled={save.isPending} className="rounded bg-brand px-4 py-1.5 text-white disabled:opacity-50">{t('payroll.confirmPay')}</button>
        </div>
      </form>
    </Modal>
  )
}

// One month's payroll: every payslip with its bonuses and deductions, and the steps that move it forward.
export default function PayrollRunPage({ base }) {
  const { t } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const navigate = useNavigate()
  const client = useQueryClient()
  const [dialog, setDialog] = useState(null)
  const [note, setNote] = useState('')
  const [back, setBack] = useState(false)
  const [error, setError] = useState('')
  const manage = user?.staff_type === 'accountant'
  const approve = user?.role === 'admin'
  const { data, isLoading } = useQuery({ queryKey: ['payroll', 'run', id], queryFn: async () => (await api.get(`/payroll/runs/${id}`)).data })
  const refresh = () => client.invalidateQueries({ queryKey: ['payroll'] })
  const act = useMutation({
    mutationFn: ({ path, body }) => api.post(`/payroll/runs/${id}/${path}`, body),
    onSuccess: () => { setBack(false); setError(''); refresh() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const drop = useMutation({ mutationFn: () => api.delete(`/payroll/runs/${id}`), onSuccess: () => { refresh(); navigate(`${base}/payroll`) }, onError: (err) => setError(errorOf(err, t('admin.actions.error'))) })
  const removeItem = useMutation({ mutationFn: (itemId) => api.delete(`/payroll/payslip-items/${itemId}`), onSuccess: refresh, onError: (err) => setError(errorOf(err, t('admin.actions.error'))) })
  const removeSlip = useMutation({ mutationFn: (slipId) => api.delete(`/payroll/payslips/${slipId}`), onSuccess: refresh, onError: (err) => setError(errorOf(err, t('admin.actions.error'))) })
  if (isLoading || !data) return <p className="text-slate-500">{t('common.loading')}</p>
  const run = data.data
  const currency = data.currency
  const draft = run.status === 'draft'
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('payroll.runTitle', { month: run.month })} <span className="ms-2 rounded-full bg-slate-100 px-2 py-0.5 text-sm font-normal">{t(`payroll.runStatus.${run.status}`)}</span></h1>
        <Link to={`${base}/payroll`} className="text-sm text-brand underline">{t('payroll.title')}</Link>
      </div>
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      {draft && run.return_note && <p className="rounded border border-amber-300 bg-amber-50 p-2 text-sm">{t('payroll.returned')}: {run.return_note}</p>}

      <div className="grid gap-2 sm:grid-cols-4">
        {['base', 'additions', 'deductions', 'net'].map((k) => (
          <div key={k} className="rounded-lg border bg-white p-3"><p className="text-xs text-slate-500">{t(`payroll.totals.${k}`)}</p><p className="font-bold"><Money value={run.totals[k]} currency={currency} /></p></div>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        {manage && draft && <button type="button" className="rounded bg-brand px-4 py-1.5 text-white" onClick={() => act.mutate({ path: 'submit' })}>{t('payroll.submit')}</button>}
        {manage && draft && <button type="button" className="rounded border px-4 py-1.5" onClick={() => api.post('/payroll/runs', { month: run.month }).then(refresh)}>{t('payroll.addNewcomers')}</button>}
        {manage && draft && <button type="button" className="rounded border px-4 py-1.5 text-brand" title={t('payroll.refreshHint')} onClick={() => api.post(`/payroll/runs/${run.id}/refresh-commitment`).then(refresh).catch((err) => setError(errorOf(err, t('admin.actions.error'))))}>{t('payroll.refreshFromAttendance')}</button>}
        {manage && draft && <button type="button" className="rounded border px-4 py-1.5 text-brand" onClick={() => setDialog({ type: 'rule' })}>{t('payroll.applyRuleAll')}</button>}
        {manage && draft && <button type="button" className="rounded border border-red-300 px-4 py-1.5 text-red-700" onClick={() => drop.mutate()}>{t('payroll.deleteRun')}</button>}
        {approve && run.status === 'submitted' && <button type="button" className="rounded bg-green-700 px-4 py-1.5 text-white" onClick={() => act.mutate({ path: 'approve' })}>{t('payroll.approve')}</button>}
        {approve && run.status === 'submitted' && <button type="button" className="rounded border px-4 py-1.5" onClick={() => setBack(true)}>{t('payroll.sendBack')}</button>}
        {manage && run.status === 'approved' && <button type="button" className="rounded bg-brand px-4 py-1.5 text-white" onClick={() => setDialog({ type: 'pay' })}>{t('payroll.payAll')}</button>}
      </div>
      {run.approved_by && <p className="text-sm text-slate-600">{t('payroll.approvedBy', { name: run.approved_by })}</p>}

      {back && (
        <form className="space-y-2 rounded-lg border bg-white p-3" onSubmit={(e) => { e.preventDefault(); act.mutate({ path: 'send-back', body: { note: note || null } }) }}>
          <label className="block text-sm">{t('payroll.returnNote')}<textarea rows={2} className="mt-1 w-full rounded border p-2" value={note} onChange={(e) => setNote(e.target.value)} /></label>
          <div className="flex gap-2"><button type="submit" className="rounded bg-brand px-3 py-1.5 text-white">{t('payroll.sendBack')}</button><button type="button" className="rounded border px-3 py-1.5" onClick={() => setBack(false)}>{t('admin.actions.cancel')}</button></div>
        </form>
      )}

      <div className="overflow-x-auto rounded-lg border bg-white">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-50"><tr>{['name', 'base', 'additions', 'deductions', 'net', 'pay'].map((c) => <th key={c} className="p-2 text-start font-medium">{t(`payroll.cols.${c}`)}</th>)}<th /></tr></thead>
          <tbody>
            {run.payslips.map((s) => (
              <tr key={s.id} className="border-t align-top">
                <td className="p-2 font-medium">{s.user.name}{s.paid_on && <span className="ms-2 rounded-full bg-green-100 px-2 text-xs text-green-800">{t('payroll.paid')}</span>}
                  {s.items.length > 0 && (
                    <ul className="mt-1 space-y-0.5 text-xs font-normal text-slate-600">
                      {s.items.map((i) => (
                        <li key={i.id}>{t(`payroll.kinds.${i.kind}`)}: {itemLabel(t, i)} <span dir="ltr">{i.kind === 'deduction' ? '−' : '+'}{i.amount}</span>
                          {manage && draft && !i.from_contract && <button type="button" aria-label={t('admin.actions.delete')} className="ms-2 text-red-700" onClick={() => removeItem.mutate(i.id)}>✕</button>}
                        </li>
                      ))}
                    </ul>
                  )}
                </td>
                <td className="p-2"><Money value={s.base_salary} currency={currency} /></td>
                <td className="p-2"><Money value={s.additions} currency={currency} /></td>
                <td className="p-2"><Money value={s.deductions} currency={currency} /></td>
                <td className="p-2 font-bold"><Money value={s.net} currency={currency} /></td>
                <td className="p-2"><PaymentCell slip={s} editable={manage && !s.paid_on && run.status !== 'paid'} /></td>
                <td className="p-2">
                  <span className="flex flex-wrap justify-end gap-2">
                    {manage && draft && <button type="button" className="rounded border px-2 py-1 text-brand" onClick={() => setDialog({ type: 'item', slip: s })}>{t('payroll.addBonusOrDeduction')}</button>}
                    {manage && draft && <button type="button" className="rounded border px-2 py-1 text-brand" onClick={() => setDialog({ type: 'rule', slip: s })}>{t('payroll.rule')}</button>}
                    {manage && draft && <button type="button" className="rounded border border-red-300 px-2 py-1 text-red-700" onClick={() => removeSlip.mutate(s.id)}>{t('payroll.removeFromRun')}</button>}
                    <Link className="rounded border px-2 py-1 text-brand" to={`${base}/payroll/payslips/${s.id}`}>{t('payroll.payslip')}</Link>
                  </span>
                </td>
              </tr>
            ))}
            {run.payslips.length === 0 && <tr><td colSpan={7} className="p-4 text-center text-slate-500">{t('payroll.noPayslips')}</td></tr>}
          </tbody>
        </table>
      </div>
      {dialog?.type === 'item' && <ItemDialog slip={dialog.slip} onClose={() => setDialog(null)} />}
      {dialog?.type === 'rule' && <ApplyRuleDialog run={run} slip={dialog.slip} onClose={() => setDialog(null)} />}
      {dialog?.type === 'pay' && <PayDialog run={run} onClose={() => setDialog(null)} />}
    </section>
  )
}
