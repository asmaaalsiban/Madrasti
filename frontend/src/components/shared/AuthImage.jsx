import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/client'

// A private image: fetched with the sign-in token and shown from a blob (an <img src> cannot send the token).
export default function AuthImage({ path, alt = '', className = '', onClick }) {
  const { data } = useQuery({ queryKey: ['auth-image', path], queryFn: async () => URL.createObjectURL((await api.get(path, { responseType: 'blob' })).data), staleTime: Infinity })
  if (!data) return <div className={`animate-pulse bg-slate-100 ${className}`} aria-hidden />
  return <img src={data} alt={alt} className={className} onClick={onClick} />
}
