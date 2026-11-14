import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import Modal from '../../../components/shared/Modal'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

// Scores one student against the criteria (1-5, or leave empty), with a note, and can add a quick positive / negative note.
export default function EvaluateDialog({ student, date, onClose }) {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const existing = student.evaluation
  const [scores, setScores] = useState(existing ? { ...existing.scores } : {})
  const [specialScores, setSpecialScores] = useState(existing ? { ...(existing.special_scores ?? {}) } : {})
  const [specialName, setSpecialName] = useState('')
  const [note, setNote] = useState(existing?.note ?? '')
  const [visible, setVisible] = useState(existing?.visible_to_parent ?? true)
  const [quick, setQuick] = useState({ type: 'positive', note: '' })
  const [error, setError] = useState('')
  const [quickSaved, setQuickSaved] = useState(false)
  const [newCriterion, setNewCriterion] = useState({ name_ar: '', name_en: '' })
  const [adding, setAdding] = useState(false)
  const name = (c) => c[`name_${i18n.language}`] || c.name_ar

  const criteria = useQuery({ queryKey: ['behavior', 'criteria'], queryFn: async () => (await api.get('/behavior/criteria')).data.data })
  const specialKey = ['behavior', 'special', student.id]
  const special = useQuery({ queryKey: specialKey, queryFn: async () => (await api.get(`/behavior/students/${student.id}/special`)).data.data })
  const addSpecial = useMutation({
    mutationFn: () => api.post(`/behavior/students/${student.id}/special`, { name: specialName }),
    onSuccess: () => { setSpecialName(''); setError(''); client.invalidateQueries({ queryKey: specialKey }); client.invalidateQueries({ queryKey: ['behavior', 'week'] }) },
    onError: (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error')),
  })
  const removeSpecial = useMutation({
    mutationFn: (id) => api.delete(`/behavior/special/${id}`),
    onSuccess: (_r, id) => { setSpecialScores((prev) => { const next = { ...prev }; delete next[id]; return next }); client.invalidateQueries({ queryKey: specialKey }); client.invalidateQueries({ queryKey: ['behavior', 'week'] }) },
  })
  const fail = (err) => setError(Object.values(err.response?.data?.errors ?? {})[0]?.[0] || t('admin.actions.error'))

  const save = useMutation({
    mutationFn: () => api.put('/behavior/evaluations', { student_id: student.id, evaluated_on: date, scores, special_scores: specialScores, note: note || null, visible_to_parent: visible }),
    onSuccess: () => { client.invalidateQueries({ queryKey: ['behavior'] }); onClose() },
    onError: fail,
  })
  const addQuick = useMutation({
    mutationFn: () => api.post('/behavior/notes', { student_id: student.id, type: quick.type, note: quick.note, noted_on: date, visible_to_parent: visible }),
    onSuccess: () => { setQuick({ ...quick, note: '' }); setQuickSaved(true); setError('') },
    onError: fail,
  })
  const addCriterion = useMutation({
    mutationFn: () => api.post('/behavior/criteria', newCriterion),
    onSuccess: () => { setNewCriterion({ name_ar: '', name_en: '' }); setAdding(false); setError(''); client.invalidateQueries({ queryKey: ['behavior', 'criteria'] }) },
    onError: fail,
  })
  const pickSpecial = (id, value) => setSpecialScores((prev) => { const next = { ...prev }; if (next[id] === value) delete next[id]; else next[id] = value; return next })
  const pick = (id, value) => setScores((prev) => { const next = { ...prev }; if (next[id] === value) delete next[id]; else next[id] = value; return next })

  return (
    <Modal open size="lg" title={t('behavior.evaluateTitle', { name: student[`name_${i18n.language}`] || student.name_ar })} onClose={onClose}>
      <div className="space-y-4">
        {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
        <p className="text-sm text-slate-600">{t('behavior.dateLine', { date })}</p>
        <ul className="space-y-2">
          {(criteria.data ?? []).filter((c) => c.is_active).map((c) => (
            <li key={c.id} className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-sm font-medium">{name(c)}</span>
              <span className="flex gap-1" role="group" aria-label={name(c)}>
                {[1, 2, 3, 4, 5].map((v) => (
                  <button
                    key={v} type="button" aria-pressed={scores[c.id] === v} title={t(`behavior.levels.${v}`)}
                    className={`h-8 w-8 rounded border text-sm ${scores[c.id] === v ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => pick(c.id, v)}
                  >{v}</button>
                ))}
              </span>
            </li>
          ))}
        </ul>
        {adding ? (
          <div className="flex flex-wrap items-end gap-2 rounded border bg-slate-50 p-2">
            <label className="text-sm">{t('behavior.nameAr')}<input className="mt-1 block rounded border p-2" value={newCriterion.name_ar} onChange={(e) => setNewCriterion({ ...newCriterion, name_ar: e.target.value })} /></label>
            <label className="text-sm">{t('behavior.nameEn')}<input dir="ltr" className="mt-1 block rounded border p-2" value={newCriterion.name_en} onChange={(e) => setNewCriterion({ ...newCriterion, name_en: e.target.value })} /></label>
            <InteractiveHoverButton type="button" className="px-3 py-2 disabled:opacity-50" disabled={!newCriterion.name_ar || !newCriterion.name_en || addCriterion.isPending} onClick={() => addCriterion.mutate()}>{t('behavior.saveCriterion')}</InteractiveHoverButton>
            <InteractiveHoverButton variant="outline" type="button" className="px-3 py-2" onClick={() => setAdding(false)}>{t('admin.actions.cancel')}</InteractiveHoverButton>
          </div>
        ) : <button type="button" className="text-sm text-brand underline" onClick={() => setAdding(true)}>{t('behavior.addCriterion')}</button>}
        <div className="space-y-2 rounded-lg border border-dashed border-brand bg-blue-50/40 p-3">
          <h3 className="font-bold">{t('behavior.special.title', { name: student[`name_${i18n.language}`] || student.name_ar })}</h3>
          <p className="text-xs text-slate-600">{t('behavior.special.help')}</p>
          {(special.data ?? []).length === 0 && <p className="text-sm text-slate-500">{t('behavior.special.none')}</p>}
          <ul className="space-y-2">
            {(special.data ?? []).map((c) => (
              <li key={c.id} className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-medium">{c.name}</span>
                <span className="flex items-center gap-1" role="group" aria-label={c.name}>
                  {[1, 2, 3, 4, 5].map((v) => (
                    <button key={v} type="button" aria-pressed={specialScores[c.id] === v} title={t(`behavior.levels.${v}`)}
                      className={`h-8 w-8 rounded border text-sm ${specialScores[c.id] === v ? 'bg-brand text-white' : 'bg-white'}`} onClick={() => pickSpecial(c.id, v)}>{v}</button>
                  ))}
                  <button type="button" aria-label={t('behavior.special.remove', { name: c.name })} className="ms-1 rounded border border-red-300 px-2 py-1 text-xs text-red-700" onClick={() => removeSpecial.mutate(c.id)}>✕</button>
                </span>
              </li>
            ))}
          </ul>
          <div className="flex flex-wrap items-center gap-2">
            <input aria-label={t('behavior.special.newName')} className="min-w-0 flex-1 rounded border p-2" placeholder={t('behavior.special.newName')} value={specialName} onChange={(e) => setSpecialName(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); if (specialName.trim()) addSpecial.mutate() } }} />
            <InteractiveHoverButton variant="outline" type="button" className="px-3 py-2 text-brand disabled:opacity-50" disabled={!specialName.trim() || addSpecial.isPending} onClick={() => addSpecial.mutate()}>{t('behavior.special.add')}</InteractiveHoverButton>
          </div>
        </div>
        <p className="text-xs text-slate-500">{[1, 2, 3, 4, 5].map((v) => `${v} = ${t(`behavior.levels.${v}`)}`).join(' · ')}</p>
        <label className="block text-sm">{t('behavior.note')}<textarea rows={3} className="mt-1 w-full rounded border p-2" value={note} onChange={(e) => setNote(e.target.value)} /></label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={visible} onChange={(e) => setVisible(e.target.checked)} />{t('behavior.visibleToParent')}</label>
        <div className="flex justify-end gap-2">
          <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5" onClick={onClose}>{t('admin.actions.cancel')}</InteractiveHoverButton>
          <InteractiveHoverButton type="button" className="px-4 py-1.5 disabled:opacity-50" disabled={save.isPending} onClick={() => save.mutate()}>{t('admin.actions.save')}</InteractiveHoverButton>
        </div>

        <div className="space-y-2 border-t pt-3">
          <h3 className="font-bold">{t('behavior.quickNote')}</h3>
          <p className="text-xs text-slate-500">{t('behavior.quickHelp')}</p>
          <div className="flex flex-wrap gap-2">
            <select aria-label={t('behavior.noteType')} className="rounded border p-2" value={quick.type} onChange={(e) => setQuick({ ...quick, type: e.target.value })}>
              <option value="positive">{t('behavior.type.positive')}</option>
              <option value="negative">{t('behavior.type.negative')}</option>
            </select>
            <input aria-label={t('behavior.quickText')} className="min-w-0 flex-1 rounded border p-2" value={quick.note} onChange={(e) => { setQuick({ ...quick, note: e.target.value }); setQuickSaved(false) }} />
            <InteractiveHoverButton variant="outline" type="button" className="px-3 py-2 disabled:opacity-50" disabled={!quick.note || addQuick.isPending} onClick={() => addQuick.mutate()}>{t('behavior.addNote')}</InteractiveHoverButton>
          </div>
          {quickSaved && <p role="status" className="text-sm text-green-700">{t('behavior.noteAdded')}</p>}
        </div>
      </div>
    </Modal>
  )
}
