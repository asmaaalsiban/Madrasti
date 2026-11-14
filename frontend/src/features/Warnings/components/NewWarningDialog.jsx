import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import { DateInput } from '../../../components/shared/DateInput'
import Modal from '../../../components/shared/Modal'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'
import { IconActionButton } from '../../../components/ui/icon-action-button'
import { SquareArrowOutUpRight } from 'lucide-react'

const CATEGORIES = ['absence', 'late', 'behavior', 'violence', 'academic', 'uniform', 'other']
const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback
const today = () => new Date().toISOString().slice(0, 10)

// Writes a warning by hand for a student (search them) or, for the admin, for a member of staff.
export default function NewWarningDialog({ canWarnStaff, initialSubject = 'student', onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const lang = i18n.language
  const [subject, setSubject] = useState(initialSubject)
  const [term, setTerm] = useState('')
  const [pick, setPick] = useState(null) // { id, label }
  const [form, setForm] = useState({ category: 'behavior', level: 1, title: '', body: '', issued_on: today() })
  const [error, setError] = useState('')
  const students = useQuery({ queryKey: ['warnings', 'students', term], enabled: subject === 'student' && term.trim().length >= 2, queryFn: async () => (await api.get('/students', { params: { search: term.trim(), per_page: 8 } })).data.data })
  const staff = useQuery({ queryKey: ['warnings', 'staff'], enabled: subject === 'staff', queryFn: async () => (await api.get('/staff', { params: { per_page: 100 } })).data.data })
  const save = useMutation({
    mutationFn: () => api.post('/warnings', { subject_type: subject, ...(subject === 'student' ? { student_id: pick.id } : { user_id: pick.id }), ...form, level: Number(form.level), body: form.body || null }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['warnings'] }); onClose() },
    onError: (err) => setError(errorOf(err, t('admin.actions.error'))),
  })
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const studentLabel = (s) => `${s[`name_${lang}`] || s.name_ar} · ${s.student_number ?? ''}`
  return (
    <Modal open size="lg" title={t('warnings.newTitle')} onClose={onClose}>
      <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        {canWarnStaff && (
          <div role="group" aria-label={t('warnings.forWhom')} className="flex gap-2 text-sm">
            {['student', 'staff'].map((k) => <button key={k} type="button" aria-pressed={subject === k} className={`rounded-full border px-4 py-1 ${subject === k ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => { setSubject(k); setPick(null); setTerm('') }}>{t(`warnings.subject.${k}`)}</button>)}
          </div>
        )}
        {pick ? (
          <p className="flex items-center justify-between rounded bg-slate-50 p-2 text-sm"><b>{pick.label}</b><IconActionButton icon={SquareArrowOutUpRight} tone="brand" label={t('warnings.change')} type="button" onClick={() => setPick(null)} /></p>
        ) : subject === 'student' ? (
          <div className="space-y-1">
            <label className="block text-sm">{t('warnings.searchStudent')}<input className="mt-1 w-full rounded border p-2" value={term} onChange={(e) => setTerm(e.target.value)} /></label>
            <ul className="divide-y rounded border text-sm">{(students.data ?? []).map((s) => <li key={s.id}><button type="button" className="w-full p-2 text-start hover:bg-slate-50" onClick={() => setPick({ id: s.id, label: studentLabel(s) })}>{studentLabel(s)}</button></li>)}</ul>
          </div>
        ) : (
          <label className="block text-sm">{t('warnings.pickStaff')}
            <select className="mt-1 w-full rounded border p-2" value="" onChange={(e) => { const x = (staff.data ?? []).find((y) => String(y.id) === e.target.value); if (x) setPick({ id: x.id, label: x.name }) }}>
              <option value="">—</option>
              {(staff.data ?? []).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
            </select>
          </label>
        )}
        <div className="grid gap-3 sm:grid-cols-3">
          <label className="block text-sm">{t('warnings.category')}<select className="mt-1 w-full rounded border p-2" value={form.category} onChange={set('category')}>{CATEGORIES.map((c) => <option key={c} value={c}>{t(`warnings.categories.${c}`)}</option>)}</select></label>
          <label className="block text-sm">{t('warnings.level')}<select className="mt-1 w-full rounded border p-2" value={form.level} onChange={set('level')}>{[1, 2, 3, 4].map((l) => <option key={l} value={l}>{t(`warnings.levels.${l}`)}</option>)}</select></label>
          <label className="block text-sm">{t('warnings.date')}<DateInput className="mt-1 w-full rounded border p-2" max={today()} value={form.issued_on} onChange={set('issued_on')} /></label>
        </div>
        <label className="block text-sm">{t('warnings.title')}<input required dir="auto" className="mt-1 w-full rounded border p-2" value={form.title} onChange={set('title')} /></label>
        <label className="block text-sm">{t('warnings.details')}<textarea rows={4} dir="auto" className="mt-1 w-full rounded border p-2" value={form.body} onChange={set('body')} /></label>
        {!canWarnStaff && <p className="text-xs text-slate-500">{t('warnings.proposalNote')}</p>}
        <div className="flex justify-end gap-2">
          <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</InteractiveHoverButton>
          <InteractiveHoverButton type="submit" disabled={!pick || save.isPending} className="px-5 py-1.5 disabled:opacity-50">{canWarnStaff ? t('warnings.issue') : t('warnings.propose')}</InteractiveHoverButton>
        </div>
      </form>
    </Modal>
  )
}
