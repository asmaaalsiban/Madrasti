import { useCallback } from 'react'
import { useMutation } from '@tanstack/react-query'
import { api } from '../../api/client'
import { useAuth } from '../Auth/AuthProvider'

// How the signed-in person arranged one surface (the principal's menu, a home page's tiles): { groups: [...keys], items: [...keys] }.
// Saved on their account, so it follows them to any device. `save(order)` shows at once and stores in the background; `reset()` goes back to the default.
export default function useLayout(surface) {
  const { user, updateUser } = useAuth()
  const stored = user?.layout?.[surface]
  const store = useMutation({ mutationFn: (order) => api.put('/auth/layout', { surface, order }) })
  const write = useCallback((order) => {
    const layout = { ...(user?.layout ?? {}) }
    // A change names only what it changes: the rest of the person's arrangement (order, size, colours) is kept.
    const next = order === null ? null : { groups: stored?.groups ?? [], items: stored?.items ?? [], ...(stored?.size ? { size: stored.size } : {}), ...(stored?.colors ? { colors: stored.colors } : {}), ...order }
    if (next === null) delete layout[surface]
    else layout[surface] = next
    updateUser({ ...user, layout })
    store.mutate(next)
  }, [user, surface, updateUser, store, stored])
  return { groups: stored?.groups ?? [], items: stored?.items ?? [], size: stored?.size ?? 'medium', colors: stored?.colors ?? {}, custom: Boolean(stored), save: (order) => write(order), reset: () => write(null) }
}
