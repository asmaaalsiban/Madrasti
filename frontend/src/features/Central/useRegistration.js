import { useQuery } from '@tanstack/react-query'
import { registrationEnabled } from './api'

// Is public school registration switched on (REGISTRATION_ENABLED on the server)? `enabled` is true only when the server says so: while
// it is loading, or when it cannot be asked, the "register a school" links stay hidden.
export function useRegistration() {
  const query = useQuery({ queryKey: ['central', 'registration'], queryFn: registrationEnabled, staleTime: Infinity, retry: false })
  return { enabled: query.data === true, loading: query.isLoading }
}
