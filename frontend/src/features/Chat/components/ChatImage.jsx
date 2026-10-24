import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// A photo sent in a chat. It is private, so it is fetched with the sign-in token and shown from a blob.
export default function ChatImage({ messageId }) {
  const { t } = useTranslation()
  const { data } = useQuery({
    queryKey: ['chat', 'image', messageId],
    queryFn: async () => URL.createObjectURL((await api.get(`/chat/messages/${messageId}/image`, { responseType: 'blob' })).data),
    staleTime: Infinity,
  })
  if (!data) return <span className="block h-24 w-32 rounded bg-slate-200" aria-hidden="true" />
  return <a href={data} target="_blank" rel="noreferrer"><img src={data} alt={t('chat.photo')} className="max-h-60 max-w-full rounded" /></a>
}
