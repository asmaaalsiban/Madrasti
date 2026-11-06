import { useQuery } from '@tanstack/react-query'
import { api } from '../../../api/client'

// A private picture of a question (or of its explanation). It is fetched with the sign-in token and shown from a blob.
export default function QuestionImage({ path, alt }) {
  const { data } = useQuery({ queryKey: ['question-image', path], queryFn: async () => URL.createObjectURL((await api.get(path, { responseType: 'blob' })).data), staleTime: Infinity })
  if (!data) return null
  return <a href={data} target="_blank" rel="noreferrer"><img src={data} alt={alt} className="max-h-64 rounded border bg-white" /></a>
}
