import { useQuery } from '@tanstack/react-query'
import { useEffect } from 'react'
import { api } from '../api/client'
import { readableBrand } from './color'

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
    // A light colour chosen by the school is darkened just enough for text to be readable on it.
    const readable = readableBrand(color)
    if (readable) document.documentElement.style.setProperty('--color-brand', readable)
  }, [color])
  return query
}
