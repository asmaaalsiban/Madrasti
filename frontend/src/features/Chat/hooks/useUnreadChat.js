import { useQuery } from '@tanstack/react-query'
import { api } from '../../../api/client'

// How many chat messages the signed-in user has not read; checked every 10 seconds.
export default function useUnreadChat() {
  const { data } = useQuery({
    queryKey: ['chat', 'unread'],
    queryFn: async () => (await api.get('/chat/unread-count')).data.data.count,
    refetchInterval: 10000,
  })
  return data ?? 0
}
