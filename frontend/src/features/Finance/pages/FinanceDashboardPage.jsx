import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Money from '../components/Money'
import PaymentSettingsCard from '../components/PaymentSettingsCard'
import BankTransferSettingsCard from '../components/BankTransferSettingsCard'

// The money of the school at a glance: expected, collected, remaining, overdue. The accountant also sets the currency.
export default function FinanceDashboardPage({ base }) {
  const { t } = useTranslation()
  const client = useQueryClient()
  const [currency, setCurrency] = useState('')
  const summary = useQuery({ queryKey: ['finance', 'summary'], queryFn: async () => (await api.get('/finance/summary')).data.data })
  const settings = useQuery({ queryKey: ['finance', 'settings'], queryFn: async () => (await api.get('/finance/settings')).data.data })
  const save = useMutation({ mutationFn: () => api.put('/finance/settings', { currency }), onSuccess: () => { setCurrency(''); client.invalidateQueries({ queryKey: ['finance'] }) } })

  const data = summary.data
  const cards = data ? [['expected', data.expected], ['collected', data.collected], ['remaining', data.remaining], ['overdue', data.overdue], ['collectedThisMonth', data.collected_this_month], ...(Number(data.carried_in) > 0 ? [['carriedIn', data.carried_in]] : []), ...(Number(data.carried_out) > 0 ? [['carriedOut', data.carried_out]] : [])] : []

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('finance.title')}</h1>
        <div className="flex gap-2">
          <Link to={`${base}/finance/accounts`} className="rounded bg-brand px-4 py-1.5 text-white">{t('finance.accounts')}</Link>
          <Link to={`${base}/finance/transfers`} className="rounded border px-4 py-1.5 text-brand">{t('transfers.title')}</Link>
          <Link to={`${base}/finance/structures`} className="rounded border px-4 py-1.5 text-brand">{t('finance.structures')}</Link>
          <Link to={`${base}/finance/expenses`} className="rounded border px-4 py-1.5 text-brand">{t('expenses.title')}</Link>
          <Link to={`${base}/finance/money`} className="rounded border px-4 py-1.5 text-brand">{t('money.title')}</Link>
          <Link to={`${base}/finance/journal`} className="rounded border px-4 py-1.5 text-brand">{t('journal.title')}</Link>
          <Link to={`${base}/finance/accounting-reports`} className="rounded border px-4 py-1.5 text-brand">{t('acctReports.title')}</Link>
          <Link to={`${base}/finance/budgets`} className="rounded border px-4 py-1.5 text-brand">{t('budgets.title')}</Link>
          <Link to={`${base}/finance/year-end`} className="rounded border px-4 py-1.5 text-brand">{t('yearEnd.title')}</Link>
        </div>
      </div>
      {data && (
        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          {cards.map(([key, value]) => (
            <div key={key} className={`rounded-lg border bg-white p-4 ${key === 'overdue' && Number(value) > 0 ? 'border-red-300' : ''}`}>
              <dt className="text-sm text-slate-500">{t(`finance.summary.${key}`)}</dt>
              <dd className="text-lg font-bold"><Money value={value} currency={data.currency} /></dd>
            </div>
          ))}
          <div className="rounded-lg border bg-white p-4"><dt className="text-sm text-slate-500">{t('finance.summary.studentsOverdue')}</dt><dd className="text-lg font-bold">{data.students_overdue}</dd></div>
        </dl>
      )}
      {settings.data?.can_manage && (
        <form className="flex flex-wrap items-end gap-2 rounded-lg bg-white p-4 shadow-sm" onSubmit={(e) => { e.preventDefault(); if (currency) save.mutate() }}>
          <label className="text-sm">{t('finance.currency')}
            <input dir="ltr" maxLength={8} className="mt-1 block w-28 rounded border p-2" placeholder={settings.data.currency} value={currency} onChange={(e) => setCurrency(e.target.value)} />
          </label>
          <button type="submit" className="rounded border px-3 py-2 text-sm" disabled={!currency}>{t('admin.actions.save')}</button>
        </form>
      )}
      <PaymentSettingsCard />
      <BankTransferSettingsCard />
    </section>
  )
}
