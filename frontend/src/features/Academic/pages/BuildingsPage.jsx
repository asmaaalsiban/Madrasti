import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'
import ConfirmDialog from '../../../components/shared/ConfirmDialog'
import FormField from '../../../components/shared/FormField'
import Modal from '../../../components/shared/Modal'
import { useResource } from '../hooks/useResource'
import { InteractiveHoverButton } from '../../../components/ui/interactive-hover-button'

const inputClass = 'w-full rounded border p-2'
const GENDERS = ['male', 'female', 'mixed']
const COUNTS = [['classrooms', 'classrooms'], ['science_labs', 'scienceLabs'], ['computer_labs', 'computerLabs']]
const EMPTY_BUILDING = { name: '', gender: 'mixed' }

// The school building: buildings > floors; a floor serves a range of grades and has classrooms and labs.
export default function BuildingsPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [editing, setEditing] = useState(null) // { kind: 'building' | 'floor', row?, building? }
  const [form, setForm] = useState({})
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [toDelete, setToDelete] = useState(null) // { kind, row }
  const [notice, setNotice] = useState('')

  const { data: buildings = [], isSuccess } = useQuery({ queryKey: ['buildings'], queryFn: async () => (await api.get('/buildings')).data.data })
  const grades = useResource('grade-levels').useList({ per_page: 100 })
  const gradeList = [...(grades.data?.data ?? [])].sort((a, b) => a.sort_order - b.sort_order)
  const gradeName = (g) => g?.[`name_${i18n.language}`] ?? g?.name_ar
  const refresh = () => client.invalidateQueries({ queryKey: ['buildings'] })

  const openBuilding = (row) => {
    setErrors({}); setFormError('')
    setForm(row ? { name: row.name, gender: row.gender } : EMPTY_BUILDING)
    setEditing({ kind: 'building', row })
  }
  const openFloor = (building, row) => {
    setErrors({}); setFormError('')
    const nextLevel = building.floors.length ? Math.max(...building.floors.map((f) => f.level)) + 1 : 0
    setForm(row ? {
      name: row.name, level: row.level, gender: row.gender ?? '', grade_from_id: row.grade_from?.id ?? '', grade_to_id: row.grade_to?.id ?? '',
      classrooms: row.counts.classroom, science_labs: row.counts.science_lab, computer_labs: row.counts.computer_lab,
    } : { name: '', level: nextLevel, gender: '', grade_from_id: '', grade_to_id: '', classrooms: 0, science_labs: 0, computer_labs: 0 })
    setEditing({ kind: 'floor', row, building })
  }

  const save = useMutation({
    mutationFn: () => {
      if (editing.kind === 'building') {
        return editing.row ? api.put(`/buildings/${editing.row.id}`, form) : api.post('/buildings', form)
      }
      const body = {
        building_id: editing.building.id, name: form.name, level: Number(form.level), gender: form.gender || null,
        grade_from_id: form.grade_from_id ? Number(form.grade_from_id) : null, grade_to_id: form.grade_to_id ? Number(form.grade_to_id) : null,
        classrooms: Number(form.classrooms), science_labs: Number(form.science_labs), computer_labs: Number(form.computer_labs),
      }
      return editing.row ? api.put(`/floors/${editing.row.id}`, body) : api.post('/floors', body)
    },
    onSuccess: () => { setEditing(null); refresh() },
    onError: (err) => {
      const fieldErrors = err.response?.data?.errors
      setErrors(fieldErrors ?? {})
      setFormError(fieldErrors ? '' : err.response?.data?.message || t('admin.actions.error'))
    },
  })

  const confirmDelete = async () => {
    const { kind, row } = toDelete
    setToDelete(null)
    setNotice('')
    try { await api.delete(`/${kind === 'building' ? 'buildings' : 'floors'}/${row.id}`); refresh() } catch (err) { setNotice(err.response?.data?.message || t('admin.actions.error')) }
  }

  const set = (name) => (e) => setForm({ ...form, [name]: e.target.value })
  const genderLabel = (g) => t(`buildings.gender.${g}`)
  const range = (f) => (f.grade_from && f.grade_to ? t('buildings.range', { from: gradeName(f.grade_from), to: gradeName(f.grade_to) }) : '—')

  return (
    <section className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold">{t('buildings.title')}</h1>
        <InteractiveHoverButton type="button" className="px-4 py-1.5" onClick={() => openBuilding(null)}>{t('buildings.addBuilding')}</InteractiveHoverButton>
      </div>
      {notice && <p role="alert" className="rounded bg-red-50 p-3 text-red-700">{notice}</p>}
      {isSuccess && buildings.length === 0 && <p className="rounded bg-white p-4 text-slate-600">{t('buildings.empty')}</p>}

      {buildings.map((b) => (
        <div key={b.id} className="space-y-3 rounded-lg bg-white p-4 shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <h2 className="text-lg font-bold">{b.name}</h2>
              <span className="rounded bg-slate-100 px-2 py-0.5 text-xs">{genderLabel(b.gender)}</span>
            </div>
            <div className="flex gap-3 text-sm">
              <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1" onClick={() => openFloor(b, null)}>{t('buildings.addFloor')}</InteractiveHoverButton>
              <button type="button" className="text-brand" onClick={() => openBuilding(b)}>{t('admin.actions.edit')}</button>
              <button type="button" className="text-red-600" onClick={() => setToDelete({ kind: 'building', row: b })}>{t('admin.actions.delete')}</button>
            </div>
          </div>
          {b.floors.length === 0 ? <p className="text-sm text-slate-500">{t('buildings.noFloors')}</p> : (
            <div className="overflow-x-auto">
              <table className="w-full text-start text-sm">
                <thead className="bg-slate-100">
                  <tr>
                    {['floor', 'studentsCol', 'grades', 'classrooms', 'scienceLabs', 'computerLabs'].map((k) => <th key={k} className="p-2 text-start font-medium">{t(`buildings.${k}`)}</th>)}
                    <th><span className="sr-only">{t('a11y.actions')}</span></th>
                  </tr>
                </thead>
                <tbody>
                  {b.floors.map((f) => (
                    <tr key={f.id} className="border-t">
                      <td className="p-2 font-medium">{f.name}</td>
                      <td className="p-2">{genderLabel(f.effective_gender)}</td>
                      <td className="p-2">{range(f)}</td>
                      <td className="p-2">{f.counts.classroom}</td>
                      <td className="p-2">{f.counts.science_lab}</td>
                      <td className="p-2">{f.counts.computer_lab}</td>
                      <td className="p-2"><div className="flex gap-3">
                        <button type="button" className="text-brand" onClick={() => openFloor(b, f)}>{t('admin.actions.edit')}</button>
                        <button type="button" className="text-red-600" onClick={() => setToDelete({ kind: 'floor', row: f })}>{t('admin.actions.delete')}</button>
                      </div></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      ))}

      <Modal open={editing !== null} title={editing?.kind === 'floor' ? t('buildings.floorForm') : t('buildings.buildingForm')} onClose={() => setEditing(null)}>
        <form onSubmit={(e) => { e.preventDefault(); save.mutate() }} className="space-y-3">
          {formError && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{formError}</p>}
          <FormField label={t('academic.fields.name')} error={errors.name?.[0]}>
            <input className={inputClass} value={form.name ?? ''} onChange={set('name')} />
          </FormField>
          {editing?.kind === 'building' ? (
            <FormField label={t('buildings.studentsCol')} error={errors.gender?.[0]}>
              <select className={inputClass} value={form.gender} onChange={set('gender')}>
                {GENDERS.map((x) => <option key={x} value={x}>{genderLabel(x)}</option>)}
              </select>
            </FormField>
          ) : (
            <>
              <FormField label={t('buildings.level')} error={errors.level?.[0]} hint={t('buildings.levelHint')}>
                <input className={inputClass} type="number" dir="ltr" value={form.level ?? ''} onChange={set('level')} />
              </FormField>
              <FormField label={t('buildings.studentsCol')} error={errors.gender?.[0]}>
                <select className={inputClass} value={form.gender ?? ''} onChange={set('gender')}>
                  <option value="">{t('buildings.sameAsBuilding')}</option>
                  {GENDERS.map((x) => <option key={x} value={x}>{genderLabel(x)}</option>)}
                </select>
              </FormField>
              {[['grade_from_id', 'fromGrade'], ['grade_to_id', 'toGrade']].map(([name, label]) => (
                <FormField key={name} label={t(`buildings.${label}`)} error={errors[name]?.[0]}>
                  <select className={inputClass} value={form[name] ?? ''} onChange={set(name)}>
                    <option value="">{t('academic.fields.select')}</option>
                    {gradeList.map((x) => <option key={x.id} value={x.id}>{gradeName(x)}</option>)}
                  </select>
                </FormField>
              ))}
              {COUNTS.map(([name, label]) => (
                <FormField key={name} label={t(`buildings.${label}`)} error={errors[name]?.[0]}>
                  <input className={inputClass} type="number" min="0" dir="ltr" value={form[name] ?? ''} onChange={set(name)} />
                </FormField>
              ))}
            </>
          )}
          <div className="flex justify-end gap-2 pt-2">
            <InteractiveHoverButton variant="outline" type="button" className="px-3 py-1.5" onClick={() => setEditing(null)}>{t('admin.actions.cancel')}</InteractiveHoverButton>
            <InteractiveHoverButton type="submit" disabled={save.isPending} className="px-3 py-1.5 disabled:opacity-60">{t('admin.actions.save')}</InteractiveHoverButton>
          </div>
        </form>
      </Modal>
      <ConfirmDialog open={toDelete !== null} message={t('admin.actions.confirmDelete')} onConfirm={confirmDelete} onCancel={() => setToDelete(null)} />
    </section>
  )
}
