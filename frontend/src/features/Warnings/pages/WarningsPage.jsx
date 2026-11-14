import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import NewWarningDialog from '../components/NewWarningDialog'
import WarningCard from '../components/WarningCard'
import RulesTab from '../components/RulesTab'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// The admin's record of warnings: students and staff, what waits for approval, writing a new one, withdrawing one.
export default function WarningsPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const lang = i18n.language
  const [subject, setSubject] = useState('student')
  const [status, setStatus] = useState('')
  const [adding, setAdding] = useState(false)
  const [withdrawing, setWithdrawing] = useState(null)
  const [reason, setReason] = useState('')
  const { data } = useQuery({ queryKey: ['warnings', 'list', subject, status], enabled: subject !== 'rules', queryFn: async () => (await api.get('/warnings', { params: { subject_type: subject, status: status || undefined } })).data })
  const refresh = () => client.invalidateQueries({ queryKey: ['warnings'] })
  const approve = useMutation({ mutationFn: (id) => api.post(`/warnings/${id}/approve`), onSuccess: refresh })
  const withdraw = useMutation({ mutationFn: () => api.post(`/warnings/${withdrawing.id}/withdraw`, { reason }), onSuccess: () => { setWithdrawing(null); setReason(''); refresh() } })
  const canManage = Boolean(data?.can_manage)
  const tab = (k) => `rounded-full px-4 py-1.5 text-sm ${subject === k ? 'bg-brand text-white' : 'border bg-white text-brand'}`
  const who = (w) => (w.subject_type === 'student' ? w.student?.[`name_${lang}`] || w.student?.name_ar : w.user?.name)
  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{t('warnings.title2')}</h1>
        <InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={() => setAdding(true)}><Plus size={16} aria-hidden />{t('warnings.new')}</InteractiveHoverButton>
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <div role="tablist" className="flex gap-2">{['student', 'staff', 'rules'].map((k) => <button key={k} type="button" role="tab" aria-selected={subject === k} className={tab(k)} onClick={() => setSubject(k)}>{k === 'rules' ? t('warnings.rules.tab') : t(`warnings.subject.${k}`)}</button>)}</div>
        {subject !== 'rules' && <label className="text-sm">{t('warnings.statusFilter')}
          <select className="ms-2 rounded border p-1.5" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t('finance.all')}</option>
            {['pending', 'active', 'withdrawn'].map((s) => <option key={s} value={s}>{t(`warnings.status.${s}`)}</option>)}
          </select>
        </label>}
      </div>
      {subject === 'rules' ? <RulesTab /> : !data ? <p className="text-slate-500">{t('common.loading')}</p> : data.data.length === 0 ? <p className="rounded-lg border bg-white p-6 text-center text-slate-500">{t('warnings.none')}</p> : (
        <div className="grid gap-3 lg:grid-cols-2">
          {data.data.map((w) => (
            <WarningCard key={w.id} w={w} who={who(w)}>
              {canManage && (
                <div className="flex gap-3 pt-1 text-sm">
                  {w.status === 'pending' && <button type="button" disabled={approve.isPending} className="rounded bg-green-700 px-3 py-1 text-white" onClick={() => approve.mutate(w.id)}>{t('warnings.approve')}</button>}
                  {w.status !== 'withdrawn' && <button type="button" className="text-red-700 underline" onClick={() => setWithdrawing(w)}>{t('warnings.withdraw')}</button>}
                </div>
              )}
            </WarningCard>
          ))}
        </div>
      )}
      {adding && <NewWarningDialog canWarnStaff={canManage} onClose={() => setAdding(false)} />}
      <Modal open={withdrawing !== null} title={t('warnings.withdrawTitle')} onClose={() => setWithdrawing(null)}>
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); withdraw.mutate() }}>
          <p className="text-sm text-slate-600">{t('warnings.withdrawHelp')}</p>
          <label className="block text-sm">{t('warnings.reason')}<input required className="mt-1 w-full rounded border p-2" value={reason} onChange={(e) => setReason(e.target.value)} /></label>
          <div className="flex justify-end gap-2"><button type="button" className="rounded border px-3 py-1.5" onClick={() => setWithdrawing(null)}>{t('admin.actions.cancel')}</button><button type="submit" disabled={withdraw.isPending} className="rounded bg-red-600 px-5 py-1.5 text-white disabled:opacity-50">{t('warnings.withdraw')}</button></div>
        </form>
      </Modal>
    </section>
  )
}
