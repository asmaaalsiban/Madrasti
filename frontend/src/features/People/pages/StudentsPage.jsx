import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import GuardianPicker from '../components/GuardianPicker'
import ResourcePage from '../../Academic/components/ResourcePage'
import { useResource } from '../../Academic/hooks/useResource'

// A heading that explains the fee fields below it.
function FeesHeading() {
  const { t } = useTranslation()
  return (
    <div className="col-span-full border-t pt-3">
      <h3 className="font-bold">{t('people.feesHeading')}</h3>
      <p className="text-xs text-slate-500">{t('people.feesHelp')}</p>
    </div>
  )
}

const fields = [
  { name: 'student_number', label: 'people.fields.studentNumber' },
  { name: 'name_ar', label: 'academic.fields.nameAr' },
  { name: 'name_en', label: 'academic.fields.nameEn', hint: 'people.nameEnHint' },
  { name: 'birth_date', label: 'people.fields.birthDate', type: 'date' },
  {
    name: 'gender',
    label: 'people.fields.gender',
    type: 'select',
    options: [{ value: 'male', label: 'people.male' }, { value: 'female', label: 'people.female' }],
  },
  { name: 'classroom_id', label: 'people.fields.classroom', type: 'select', optionsFrom: 'classrooms', optionLabel: 'name', getValue: (row) => row.classroom?.id },
  // The money of the student, in one place: the grade's yearly fee is applied by itself (see Finance > Yearly fees); here are what differs per student.
  // Transport can change between terms (empty = no bus); the discount and the first payment are given when the student is added.
  { name: 'fees_heading', type: 'custom', visibleWhen: (f) => Boolean(f.classroom_id), render: () => <FeesHeading /> },
  { name: 'transport_fee', label: 'people.fields.transportFee', hint: 'people.transportHint', type: 'number', getValue: (row) => row.transport_fee ?? '', visibleWhen: (f) => Boolean(f.classroom_id) },
  { name: 'fee_discount', label: 'people.fields.feeDiscount', hint: 'people.discountHint', type: 'number', getValue: () => '', visibleWhen: (f) => f.is_new_student && f.classroom_id },
  { name: 'first_payment', label: 'people.fields.firstPayment', hint: 'people.firstPaymentHint', type: 'number', getValue: () => '', visibleWhen: (f) => f.is_new_student && f.classroom_id },
  { name: 'first_payment_payer', label: 'people.fields.firstPaymentPayer', hint: 'people.firstPaymentPayerHint', getValue: () => '', visibleWhen: (f) => f.is_new_student && f.classroom_id && f.first_payment },
  {
    name: 'first_payment_method', label: 'people.fields.firstPaymentMethod', type: 'select', getValue: () => 'cash', visibleWhen: (f) => f.is_new_student && f.classroom_id && f.first_payment,
    options: ['cash', 'bank_transfer', 'card', 'cheque', 'online'].map((m) => ({ value: m, label: `finance.methods.${m}` })),
  },
  { name: 'guardian_picker', type: 'custom', render: ({ setForm }) => <GuardianPicker setForm={setForm} /> },
  { name: 'guardian_email', label: 'people.fields.guardianEmail', type: 'email', getValue: (row) => row.guardians?.[0]?.email },
  { name: 'guardian_name', label: 'people.fields.guardianName', getValue: (row) => row.guardians?.[0]?.name },
  { name: 'guardian_phone', label: 'people.fields.guardianPhone', getValue: (row) => row.guardians?.[0]?.phone },
]

const baseColumns = [
  { key: 'student_number', label: 'people.fields.studentNumber' },
  { key: 'name_ar', label: 'academic.fields.nameAr' },
  { key: 'email', label: 'people.fields.studentEmail' },
  { key: 'classroom', label: 'people.fields.classroom', render: (r) => r.classroom?.name },
  { key: 'guardians', label: 'people.fields.guardians', render: (r) => r.guardians?.map((g) => g.email).join(', ') },
]

const rowActions = [
  {
    key: 'reset',
    label: 'people.actions.resetPassword',
    confirm: 'people.actions.resetConfirm',
    done: 'people.actions.resetDone',
    run: (row) => api.post(`/users/${row.user_id}/reset-password`),
  },
  {
    key: 'reset-guardian',
    label: 'people.actions.resetGuardianPassword',
    confirm: 'people.actions.resetConfirm',
    done: 'people.actions.resetDone',
    when: (row) => Boolean(row.guardians?.length),
    run: (row) => api.post(`/users/${row.guardians[0].id}/reset-password`),
  },
]

export default function StudentsPage() {
  const { t } = useTranslation()
  const settings = useQuery({ queryKey: ['finance', 'settings'], queryFn: async () => (await api.get('/finance/settings')).data.data })
  // What each student still owes this year, a link to their account (fees, installments, payments).
  const columns = [...baseColumns, {
    key: 'balance', label: 'people.fields.remaining',
    render: (r) => (r.balance ? <Link className={`underline ${Number(r.balance.overdue) > 0 ? 'font-bold text-red-700' : 'text-brand'}`} dir="ltr" to={`/admin/finance/students/${r.id}`}>{`${r.balance.remaining} ${settings.data?.currency ?? ''}`}</Link> : '—'),
  }]
  const [classroom, setClassroom] = useState('')
  const classrooms = useResource('classrooms').useList({ per_page: 100 })
  const [account, setAccount] = useState(null)
  return (
    <>
      <label className="mb-3 block text-sm">
        {t('people.filterClassroom')}
        <select className="mt-1 block rounded border p-2" value={classroom} onChange={(e) => setClassroom(e.target.value)}>
          <option value="">{t('people.allClassrooms')}</option>
          {(classrooms.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </label>
      <ResourcePage
        key={classroom}
        filters={classroom ? { classroom_id: classroom } : {}}
        path="students"
        title="people.students"
        columns={columns}
        fields={fields}
        empty={{
          student_number: '', name_ar: '', name_en: '', birth_date: '', gender: '',
          classroom_id: '', guardian_email: '', guardian_name: '', guardian_phone: '', is_new_student: true, transport_fee: '', fee_discount: '', first_payment: '', first_payment_method: 'cash', first_payment_payer: '',
        }}
        rowActions={rowActions}
        onSaved={(response, isNew) => isNew && setAccount(response?.data?.account ? { ...response.data.account, fees: response.data.created_fees ?? [], payments: response.data.created_payments ?? [] } : null)}
      />
      <Modal open={account !== null} title={t('people.accountCreated.title')} onClose={() => setAccount(null)}>
        <p className="mb-3 text-sm text-slate-600">{t('people.accountCreated.text')}</p>
        <dl className="space-y-2">
          <div><dt className="text-sm text-slate-500">{t('people.accountCreated.email')}</dt><dd dir="ltr" className="font-mono">{account?.email}</dd></div>
          <div><dt className="text-sm text-slate-500">{t('people.accountCreated.password')}</dt><dd dir="ltr" className="font-mono">{account?.password}</dd></div>
        </dl>
        {account?.fees?.length > 0 && (
          <div className="mt-4 space-y-1 border-t pt-3">
            <p className="text-sm font-medium">{t('people.accountCreated.fees')}</p>
            <ul className="text-sm text-slate-700">
              {account.fees.map((f) => <li key={f.description}>{f.description}: <span dir="ltr">{f.net}</span> ({t('people.accountCreated.installments', { count: f.installments })})<ul className="ms-4 list-disc text-xs text-slate-500">{f.items?.map((i) => <li key={i.description}>{i.description}: <span dir="ltr">{i.amount}</span></li>)}</ul></li>)}
            </ul>
          </div>
        )}
        {account?.payments?.length > 0 && (
          <div className="mt-3 space-y-1 border-t pt-3">
            <p className="text-sm font-medium">{t('people.accountCreated.payments')}</p>
            <ul className="text-sm text-slate-700">{account.payments.map((p) => <li key={p.receipt}><span dir="ltr">{p.receipt}</span>: <span dir="ltr">{p.amount}</span></li>)}</ul>
          </div>
        )}
        <div className="flex justify-end pt-4">
          <button type="button" className="rounded bg-brand px-3 py-1.5 text-white" onClick={() => setAccount(null)}>{t('people.accountCreated.close')}</button>
        </div>
      </Modal>
    </>
  )
}
