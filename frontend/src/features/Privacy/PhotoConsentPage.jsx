import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'

const MODES = ['required', 'warn', 'off']

// The principal: how strict the school is about photos of children, and the answer of every family (record a paper form on their behalf).
export default function PhotoConsentPage() {
  const { t, i18n } = useTranslation()
  const client = useQueryClient()
  const [room, setRoom] = useState('')
  const rooms = useQuery({ queryKey: ['classrooms', 'all'], queryFn: async () => (await api.get('/classrooms', { params: { per_page: 100 } })).data.data })
  const { data } = useQuery({ queryKey: ['privacy', 'photo', room], queryFn: async () => (await api.get('/privacy/photo-consent', { params: room ? { classroom_id: room } : {} })).data })
  const refresh = () => client.invalidateQueries({ queryKey: ['privacy'] })
  const setMode = useMutation({ mutationFn: (mode) => api.put('/privacy/photo-consent-mode', { mode }), onSuccess: refresh })
  const set = useMutation({ mutationFn: ({ id, allowed }) => api.put('/privacy/photo-consent', { student_id: id, allowed }), onSuccess: refresh })
  const nameOf = (s) => s[`name_${i18n.language}`] || s.name_ar
  return (
    <section className="space-y-4">
      <h1 className="text-xl font-bold">{t('photoConsent.title')}</h1>
      <p className="text-sm text-slate-600">{t('photoConsent.help')}</p>
      <fieldset className="space-y-2 rounded-lg border bg-white p-4">
        <legend className="px-1 font-bold">{t('photoConsent.mode')}</legend>
        {MODES.map((m) => (
          <label key={m} className={`flex cursor-pointer items-start gap-2 rounded border p-2 text-sm ${data?.mode === m ? 'border-brand bg-sky-tint/40' : ''}`}>
            <input type="radio" name="photo-mode" className="mt-1" checked={data?.mode === m} onChange={() => setMode.mutate(m)} />
            <span><b>{t(`photoConsent.modes.${m}`)}</b><span className="block text-slate-600">{t(`photoConsent.modeHelp.${m}`)}</span></span>
          </label>
        ))}
      </fieldset>
      <div className="flex flex-wrap items-center gap-2">
        <label className="text-sm">{t('photoConsent.section')}
          <select className="ms-2 rounded border p-1.5" value={room} onChange={(e) => setRoom(e.target.value)}>
            <option value="">{t('photoConsent.all')}</option>
            {(rooms.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </label>
        {data && <span className="text-sm text-slate-600">{t('photoConsent.missing', { count: data.missing })}</span>}
      </div>
      <div className="overflow-x-auto rounded-lg border bg-white">
        <table className="w-full text-start text-sm">
          <thead className="bg-slate-50"><tr><th className="p-2 text-start font-medium">{t('photoConsent.student')}</th><th className="p-2 text-start font-medium">{t('photoConsent.answer')}</th><th /></tr></thead>
          <tbody>
            {(data?.data ?? []).map((s) => (
              <tr key={s.id} className="border-t">
                <td className="p-2 font-medium"><bdi>{nameOf(s)}</bdi></td>
                <td className={`p-2 ${s.photo_consent === true ? 'text-green-700' : s.photo_consent === false ? 'text-red-700' : 'text-amber-700'}`}>{t(`privacy.photo.state.${s.photo_consent === true ? 'yes' : s.photo_consent === false ? 'no' : 'unknown'}`)}</td>
                <td className="p-2"><span className="flex justify-end gap-2">
                  <button type="button" aria-label={t('privacy.photo.allowFor', { name: nameOf(s) })} className="rounded border px-3 py-1 text-green-800" onClick={() => set.mutate({ id: s.id, allowed: true })}>{t('privacy.photo.allow')}</button>
                  <button type="button" aria-label={t('privacy.photo.denyFor', { name: nameOf(s) })} className="rounded border px-3 py-1 text-red-800" onClick={() => set.mutate({ id: s.id, allowed: false })}>{t('privacy.photo.deny')}</button>
                </span></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}
