import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// The picture of a homework. It is private, so it is fetched with the sign-in token and shown from a blob (an <img src> cannot send the token).
export default function HomeworkImage({ id }) {
  const { t } = useTranslation()
  const { data } = useQuery({
    queryKey: ['homework', 'image', id],
    queryFn: async () => URL.createObjectURL((await api.get(`/homework/${id}/image`, { responseType: 'blob' })).data),
    staleTime: Infinity,
  })
  if (!data) return null
  return (
    <a href={data} target="_blank" rel="noreferrer">
      <img src={data} alt={t('homework.imageAlt')} className="mt-2 max-h-64 rounded border" />
    </a>
  )
}
