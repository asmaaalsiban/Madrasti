import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/client'

const NONE = { notifications: 0, announcements: 0, chat: 0, surveys: 0 }

// The counters at the top of every screen (unread notifications, announcements, chat messages, surveys waiting) in ONE request,
// checked every 30 seconds while the tab is open. One request instead of four is half of the server's idle work (see loadtest/README.md).
// After an action that changes a counter (reading a notification, answering a survey), invalidate ['badges'].
export default function useBadges() {
  const { data } = useQuery({ queryKey: ['badges'], queryFn: async () => (await api.get('/badges')).data.data, refetchInterval: 30000, staleTime: 15000 })
  return data ?? NONE
}
