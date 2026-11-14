import { useQuery } from '@tanstack/react-query'
import { Check } from 'lucide-react'
import { api } from '../../api/client'

// The round picture of a person, drawn from their `avatar` (see the API): a ready-made character (/avatars/aNN.svg), an uploaded photo
// (fetched with the sign-in token, because it is private), or, when there is none, their initials on a colour that stays the same for the name.
// ring: a coloured ring around it. badge: 'verified' (a check) or 'online' (a green dot) in the corner. Pass `name` to name it for screen readers;
// leave it out next to the name (the picture is then decorative).

const COLORS = ['#1d4ed8', '#0f766e', '#be185d', '#7c3aed', '#b45309', '#0369a1', '#15803d', '#9f1239']

const initialsOf = (name = '') => {
  const words = name.trim().split(/\s+/).filter(Boolean)
  if (words.length === 0) return '?'
  return words.length === 1 ? [...words[0]][0] : [...words[0]][0] + [...words[1]][0]
}

const colorOf = (name = '') => COLORS[[...name].reduce((sum, ch) => sum + ch.codePointAt(0), 0) % COLORS.length]

// A private photo: asked for once per picture (the version changes when the person changes it), then kept as an object URL.
function usePhoto(avatar) {
  const url = avatar?.type === 'photo' ? avatar.url : null
  const query = useQuery({
    queryKey: ['avatar-photo', url, avatar?.v],
    enabled: Boolean(url),
    staleTime: Infinity,
    gcTime: Infinity,
    retry: false,
    queryFn: async () => URL.createObjectURL((await api.get(url, { responseType: 'blob' })).data),
  })
  return query.data ?? null
}

export default function Avatar({ user, name, size = 40, ring = false, badge = null, className = '' }) {
  const who = name ?? user?.name ?? ''
  const avatar = user?.avatar ?? null
  const photo = usePhoto(avatar)
  const src = avatar?.type === 'preset' ? `/avatars/${avatar.key}.svg` : photo
  const label = name !== undefined ? { role: 'img', 'aria-label': name } : { 'aria-hidden': true }
  return (
    <span className={`relative inline-block shrink-0 ${className}`} style={{ width: size, height: size }} {...label}>
      <span className={`block h-full w-full overflow-hidden rounded-full ${ring ? 'ring-2 ring-brand ring-offset-2 ring-offset-white' : ''}`}>
        {src
          ? <img src={src} alt="" draggable={false} className="h-full w-full object-cover" />
          : <span className="grid h-full w-full place-items-center font-bold text-white" style={{ background: colorOf(who), fontSize: size * 0.4 }}>{initialsOf(who)}</span>}
      </span>
      {badge === 'verified' && <span className="absolute -bottom-0.5 -end-0.5 grid place-items-center rounded-full border-2 border-white bg-teal-600 text-white" style={{ width: Math.max(14, size * 0.3), height: Math.max(14, size * 0.3) }}><Check aria-hidden size={Math.max(8, size * 0.18)} strokeWidth={3.5} /></span>}
      {badge === 'online' && <span className="absolute bottom-0 end-0 rounded-full border-2 border-white bg-green-500" style={{ width: Math.max(10, size * 0.26), height: Math.max(10, size * 0.26) }} />}
    </span>
  )
}
