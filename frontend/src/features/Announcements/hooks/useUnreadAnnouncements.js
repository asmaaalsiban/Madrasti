import { useQuery } from '@tanstack/react-query'
import { api } from '../../../api/client'

// How many announcements the signed-in user has not opened; checked again every 30 seconds.
export default function useUnreadAnnouncements() {
  const { data } = useQuery({
    queryKey: ['announcements', 'unread'],
    queryFn: async () => (await api.get('/announcements/unread-count')).data.data.count,
    refetchInterval: 30000,
  })
  return data ?? 0
}
