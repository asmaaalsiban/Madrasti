import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { useAuth } from '../../Auth/AuthProvider'

const CODES = ['punctuality', 'daily_summary', 'weekly_plan']

function Amount({ type, value, onType, onValue, disabled, label }) {
  const { t } = useTranslation()
  return (
    <span className="flex items-center gap-1">
      <input aria-label={label} type="number" min="0" step="0.01" dir="ltr" disabled={disabled} className="w-24 rounded border p-1.5" value={value} onChange={(e) => onValue(e.target.value)} />
      <select aria-label={`${label} ${t('payroll.rulesType')}`} disabled={disabled} className="rounded border p-1.5" value={type} onChange={(e) => onType(e.target.value)}>
        <option value="percent">{t('payroll.rulesPercent')}</option>
        <option value="amount">{t('payroll.rulesAmount')}</option>
      </select>
    </span>
  )
}

// The commitment rules: a score per measure, a bonus above it and a deduction below it. The principal sets them.
export default function RulesPage({ base }) {
  const { t } = useTranslation()
  const { data, isError } = useQuery({ queryKey: ['payroll', 'rules'], retry: false, queryFn: async () => (await api.get('/payroll/rules')).data.data })
  if (isError) return <section><h1 className="text-xl font-bold">{t('payroll.rulesNoAccessTitle')}</h1><p className="mt-2 text-slate-600">{t('payroll.rulesNoAccess')}</p></section>
  if (!data) return <p className="text-slate-500">{t('common.loading')}</p>
  return <RulesForm base={base} initial={data} />
}

function RulesForm({ base, initial }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const client = useQueryClient()
  const canEdit = user?.role === 'admin'
  const [rules, setRules] = useState(initial)
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState('')
  const save = useMutation({
    mutationFn: () => api.put('/payroll/rules', rules),
    onSuccess: () => { setError(''); setSaved(true); client.invalidateQueries({ queryKey: ['payroll'] }) },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')),
  })
  const setTier = (code, i, patch) => set(code, { deduction_tiers: rules[code].deduction_tiers.map((x, j) => (j === i ? { ...x, ...patch } : x)) })
  const set = (code, patch) => { setSaved(false); setRules({ ...rules, [code]: { ...rules[code], ...patch } }) }
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('payroll.rulesTitle')}</h1>
        <Link to={`${base}/payroll`} className="rounded border px-3 py-1.5 text-sm text-brand">{t('payroll.title')}</Link>
      </div>
      <p className="text-sm text-slate-600">{t('payroll.rulesHelp')}</p>
      <label className="block text-sm">{t('payroll.rulesGrace')}<input type="number" min="0" max="120" dir="ltr" disabled={!canEdit} className="ms-2 w-20 rounded border p-1.5" value={rules.grace_minutes} onChange={(e) => { setSaved(false); setRules({ ...rules, grace_minutes: e.target.value }) }} /></label>
      {CODES.map((code) => {
        const r = rules[code]
        return (
          <fieldset key={code} className="space-y-3 rounded-lg border bg-white p-4">
            <legend className="px-1 font-bold">{t(`payroll.rulesCodes.${code}`)}</legend>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" disabled={!canEdit} checked={r.enabled} onChange={(e) => set(code, { enabled: e.target.checked })} />{t('payroll.rulesEnabled')}</label>
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="space-y-1 rounded border border-green-200 bg-green-50 p-3 text-sm">
                <p className="font-medium text-green-800">{t('payroll.rulesBonus')}</p>
                <label className="flex flex-wrap items-center gap-2">{t('payroll.rulesAtLeast')}<input aria-label={`${t(`payroll.rulesCodes.${code}`)} ${t('payroll.rulesAtLeast')}`} type="number" min="1" max="100" dir="ltr" disabled={!canEdit} className="w-20 rounded border p-1.5" value={r.bonus_min} onChange={(e) => set(code, { bonus_min: e.target.value })} />%</label>
                <Amount label={`${t(`payroll.rulesCodes.${code}`)} ${t('payroll.rulesBonus')}`} type={r.bonus_type} value={r.bonus_value} disabled={!canEdit} onType={(v) => set(code, { bonus_type: v })} onValue={(v) => set(code, { bonus_value: v })} />
              </div>
              <div className="space-y-2 rounded border border-red-200 bg-red-50 p-3 text-sm">
                <p className="font-medium text-red-800">{t('payroll.rulesDeduction')}</p>
                <p className="text-xs text-slate-600">{t('payroll.tiersHelp')}</p>
                {r.deduction_tiers.map((tier, i) => (
                  <div key={i} className="flex flex-wrap items-center gap-2">
                    <span>{t('payroll.rulesBelow')}</span>
                    <input aria-label={`${t(`payroll.rulesCodes.${code}`)} ${t('payroll.rulesBelow')} ${i + 1}`} type="number" min="1" max="100" dir="ltr" disabled={!canEdit} className="w-16 rounded border p-1.5" value={tier.below} onChange={(e) => setTier(code, i, { below: e.target.value })} />%
                    <span>→</span>
                    <Amount label={`${t(`payroll.rulesCodes.${code}`)} ${t('payroll.rulesDeduction')} ${i + 1}`} type={tier.type} value={tier.value} disabled={!canEdit} onType={(v) => setTier(code, i, { type: v })} onValue={(v) => setTier(code, i, { value: v })} />
                    {canEdit && <button type="button" aria-label={t('admin.actions.delete')} className="text-red-700" onClick={() => set(code, { deduction_tiers: r.deduction_tiers.filter((_, j) => j !== i) })}>✕</button>}
                  </div>
                ))}
                {canEdit && (
                  <div className="flex flex-wrap gap-3">
                    <button type="button" className="rounded border border-red-300 bg-white px-3 py-1 text-red-800" onClick={() => set(code, { deduction_tiers: [...r.deduction_tiers, { below: 60, type: 'amount', value: '' }] })}>{t('payroll.addTier')}</button>
                    {r.deduction_tiers.length === 0 && <button type="button" className="rounded border bg-white px-3 py-1 text-brand" onClick={() => set(code, { deduction_tiers: [{ below: 80, type: 'amount', value: 10 }, { below: 60, type: 'amount', value: 25 }, { below: 50, type: 'percent', value: 20 }] })}>{t('payroll.suggestedTiers')}</button>}
                  </div>
                )}
              </div>
            </div>
          </fieldset>
        )
      })}
      <fieldset className="space-y-3 rounded-lg border bg-white p-4">
        <legend className="px-1 font-bold">{t('payroll.absenceTitle')}</legend>
        <p className="text-xs text-slate-600">{t('payroll.absenceHelp')}</p>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" disabled={!canEdit} checked={rules.absence.enabled} onChange={(e) => { setSaved(false); setRules({ ...rules, absence: { ...rules.absence, enabled: e.target.checked } }) }} />{t('payroll.rulesEnabled')}</label>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span>{t('payroll.perAbsentDay')}</span>
          <Amount label={t('payroll.absenceTitle')} type={rules.absence.type} value={rules.absence.value} disabled={!canEdit} onType={(v) => { setSaved(false); setRules({ ...rules, absence: { ...rules.absence, type: v } }) }} onValue={(v) => { setSaved(false); setRules({ ...rules, absence: { ...rules.absence, value: v } }) }} />
          {rules.absence.type === 'percent' && <span className="text-xs text-slate-500">{t('payroll.ofDayPay')}</span>}
        </div>
      </fieldset>
      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
      {saved && <p role="status" className="rounded bg-green-50 p-2 text-sm text-green-800">{t('payroll.rulesSaved')}</p>}
      {canEdit && <button type="button" disabled={save.isPending} className="rounded bg-brand px-4 py-2 text-white disabled:opacity-50" onClick={() => save.mutate()}>{t('admin.actions.save')}</button>}
    </section>
  )
}
