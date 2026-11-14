import { useMutation } from '@tanstack/react-query'
import { Camera, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import Avatar from '../../components/shared/Avatar'
import { useAuth } from '../Auth/AuthProvider'

// The ready-made characters are /avatars/a01.svg ... a24.svg (scripts/make-avatars.mjs); the server accepts exactly these keys.
const KEYS = Array.from({ length: 24 }, (_, i) => `a${String(i + 1).padStart(2, '0')}`)

const errorOf = (err, fallback) => Object.values(err.response?.data?.errors ?? {})[0]?.[0] || err.response?.data?.message || fallback

// "My profile": the person's picture. A photo (everybody except students: no real photos of children on the platform) or a ready-made
// character; or neither, and the initials show.
export default function ProfilePage() {
  const { t } = useTranslation()
  const { user, updateUser } = useAuth()
  const file = useRef(null)
  const [error, setError] = useState('')
  const isStudent = user.role === 'student'

  const done = { onSuccess: (r) => { setError(''); updateUser({ ...user, ...r.data.data }) }, onError: (err) => setError(errorOf(err, t('admin.actions.error'))) }
  const upload = useMutation({ mutationFn: (photo) => { const body = new FormData(); body.append('photo', photo); return api.post('/profile/avatar', body) }, ...done })
  const choose = useMutation({ mutationFn: (key) => api.put('/profile/avatar/preset', { key }), ...done })
  const remove = useMutation({ mutationFn: () => api.delete('/profile/avatar'), ...done })
  const busy = upload.isPending || choose.isPending || remove.isPending
  const current = user.avatar?.type === 'preset' ? user.avatar.key : null

  return (
    <section className="mx-auto max-w-2xl space-y-6">
      <h1 className="text-2xl font-bold text-deep-harbor">{t('profile.title')}</h1>

      <div className="flex items-center gap-5 rounded-2xl border bg-snow p-5">
        <Avatar user={user} name={user.name} size={96} ring badge="verified" />
        <div className="min-w-0">
          <p className="truncate text-lg font-bold text-deep-harbor">{user.name}</p>
          <p className="text-sm text-slate-500">{t(`roles.${user.role}`)}</p>
        </div>
      </div>

      {error && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}

      <div className="space-y-3 rounded-2xl border bg-snow p-5">
        <h2 className="font-bold text-deep-harbor">{t('profile.photo')}</h2>
        {isStudent ? (
          <p className="text-sm text-slate-600">{t('profile.studentsNoPhoto')}</p>
        ) : (
          <>
            <p className="text-sm text-slate-600">{t('profile.photoHelp')}</p>
            <input ref={file} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" aria-label={t('profile.choosePhoto')} onChange={(e) => { const photo = e.target.files?.[0]; if (photo) upload.mutate(photo); e.target.value = '' }} />
            <button type="button" disabled={busy} className="inline-flex items-center gap-2 rounded-full border px-4 py-2 font-semibold text-brand disabled:opacity-60" onClick={() => file.current?.click()}>
              <Camera aria-hidden size={18} />{t('profile.choosePhoto')}
            </button>
          </>
        )}
      </div>

      <div className="space-y-3 rounded-2xl border bg-snow p-5">
        <h2 className="font-bold text-deep-harbor">{t('profile.characters')}</h2>
        <p className="text-sm text-slate-600">{t('profile.charactersHelp')}</p>
        <ul className="grid grid-cols-4 gap-3 sm:grid-cols-6">
          {KEYS.map((key) => (
            <li key={key}>
              <button type="button" disabled={busy} aria-pressed={current === key} aria-label={t('profile.character', { number: Number(key.slice(1)) })} className={`block w-full rounded-full p-0.5 transition-transform hover:scale-105 disabled:opacity-60 ${current === key ? 'ring-2 ring-brand ring-offset-2' : ''}`} onClick={() => choose.mutate(key)}>
                <img src={`/avatars/${key}.svg`} alt="" draggable={false} className="aspect-square w-full rounded-full" />
              </button>
            </li>
          ))}
        </ul>
        {user.avatar && (
          <button type="button" disabled={busy} className="inline-flex items-center gap-2 text-sm font-semibold text-red-700 disabled:opacity-60" onClick={() => remove.mutate()}>
            <Trash2 aria-hidden size={16} />{t('profile.remove')}
          </button>
        )}
      </div>
    </section>
  )
}
