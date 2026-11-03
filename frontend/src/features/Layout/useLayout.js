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
    if (order === null) delete layout[surface]
    else layout[surface] = order
    updateUser({ ...user, layout })
    store.mutate(order)
  }, [user, surface, updateUser, store])
  return { groups: stored?.groups ?? [], items: stored?.items ?? [], custom: Boolean(stored), save: (order) => write(order), reset: () => write(null) }
}
