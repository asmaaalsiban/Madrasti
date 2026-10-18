import { useQuery } from '@tanstack/react-query'
import { useEffect } from 'react'
import { api } from '../api/client'

// School name / logo / color come from the API by domain.
export function useSchool() {
  const query = useQuery({
    queryKey: ['school'],
    queryFn: () => api.get('/school').then((r) => r.data),
    staleTime: Infinity,
    retry: false,
  })
  const color = query.data?.primary_color
  useEffect(() => {
    if (color) document.documentElement.style.setProperty('--color-brand', color)
  }, [color])
  return query
}
