import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'

// The directorate of education and the area, printed on the timetable and the subject distribution forms.
export default function SchoolDetailsCard() {
  const { t } = useTranslation()
  const client = useQueryClient()
  const { data } = useQuery({ queryKey: ['school-details'], queryFn: async () => (await api.get('/school-details')).data.data })
  const [edit, setEdit] = useState(null)
  const [saved, setSaved] = useState(false)
  const save = useMutation({
    mutationFn: (body) => api.put('/school-details', body),
    onSuccess: () => { setSaved(true); setEdit(null); client.invalidateQueries({ queryKey: ['school-details'] }) },
  })
  if (!data) return null
  const form = edit ?? data
  const set = (k, v) => { setSaved(false); setEdit({ ...form, [k]: v }) }
  return (
    <form className="space-y-3 rounded-lg border bg-white p-4" onSubmit={(e) => { e.preventDefault(); save.mutate(form) }}>
      <h2 className="text-lg">{t('schoolDetails.title')}</h2>
      <p className="text-sm text-slate-600">{t('schoolDetails.help')}</p>
      {saved && <p role="status" className="rounded bg-green-50 p-2 text-sm text-green-800">{t('schoolDetails.saved')}</p>}
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block text-sm">{t('schoolDetails.directorate')}<input className="mt-1 w-full rounded border p-2" value={form.directorate} onChange={(e) => set('directorate', e.target.value)} /></label>
        <label className="block text-sm">{t('schoolDetails.district')}<input className="mt-1 w-full rounded border p-2" value={form.district} onChange={(e) => set('district', e.target.value)} /></label>
      </div>
      <div className="flex justify-end"><button type="submit" disabled={save.isPending} className="rounded bg-brand px-5 py-1.5 text-white disabled:opacity-50">{t('admin.actions.save')}</button></div>
    </form>
  )
}
