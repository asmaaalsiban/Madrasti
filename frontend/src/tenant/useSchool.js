import { useQuery } from '@tanstack/react-query'
import { useEffect } from 'react'
import { api } from '../api/client'
import { applyTheme, clearTheme } from './theme'

// School name / logo / color come from the API by domain.
export function useSchool() {
  const query = useQuery({
    queryKey: ['school'],
    queryFn: () => api.get('/school').then((r) => r.data),
    staleTime: Infinity,
    retry: false,
  })
  const primary = query.data?.primary_color
  const dark = query.data?.dark_color
  useEffect(() => {
    // The colours come from the school's logo (set by its principal). A light colour is darkened just enough for text to be readable on it.
    if (primary) applyTheme({ primary, dark })
    else clearTheme()
  }, [primary, dark])
  return query
}
