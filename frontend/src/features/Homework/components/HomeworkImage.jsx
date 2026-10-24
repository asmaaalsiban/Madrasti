import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../../api/client'

// One private photo (of a homework or of an answer). It is fetched with the sign-in token and shown from a blob (an <img src> cannot send the token).
export default function HomeworkImage({ fileId, label = null }) {
  const { t } = useTranslation()
  const { data } = useQuery({
    queryKey: ['homework', 'file', fileId],
    queryFn: async () => URL.createObjectURL((await api.get(`/homework/files/${fileId}`, { responseType: 'blob' })).data),
    staleTime: Infinity,
  })
  if (!data) return null
  return (
    <a href={data} target="_blank" rel="noreferrer">
      <img src={data} alt={label ?? t('homework.imageAlt')} className="max-h-64 rounded border" />
    </a>
  )
}

// All the photos of a homework or an answer, in order, as "Page 1, Page 2...".
export function HomeworkPhotos({ ids }) {
  const { t } = useTranslation()
  if (!ids?.length) return null
  return (
    <div className="mt-2 flex flex-wrap gap-2">
      {ids.map((id, i) => <HomeworkImage key={id} fileId={id} label={t('homework.page', { number: i + 1 })} />)}
    </div>
  )
}
