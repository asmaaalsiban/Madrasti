import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import saApi from './saApi'

// Server state of the Super Admin area (TanStack Query over the central API).

export const useSchools = (params) => useQuery({
  queryKey: ['sa', 'schools', params],
  queryFn: async () => (await saApi.get('/tenants', { params })).data,
  placeholderData: keepPreviousData,
})

// There is no "show one school" endpoint: the code is unique, so the search finds exactly that school.
export const useSchoolByCode = (code) => useQuery({
  queryKey: ['sa', 'school', code],
  queryFn: async () => {
    const { data } = await saApi.get('/tenants', { params: { search: code } })
    return data.data.find((s) => s.id === code) ?? null
  },
})

export const useSchoolAudit = (code) => useQuery({
  queryKey: ['sa', 'audit', code],
  queryFn: async () => (await saApi.get(`/tenants/${code}/audit`)).data.data,
})

// Every change refreshes the list, the school and its audit.
function useAction(fn) {
  const qc = useQueryClient()
  return useMutation({ mutationFn: fn, onSuccess: () => qc.invalidateQueries({ queryKey: ['sa'] }) })
}

export const useCreateSchool = () => useAction(async (payload) => (await saApi.post('/tenants', payload)).data.data)
export const useUpdateSchool = (code) => useAction(async (payload) => (await saApi.patch(`/tenants/${code}`, payload)).data.data)
export const useSuspendSchool = (code) => useAction(async () => (await saApi.post(`/tenants/${code}/suspend`)).data.data)
export const useReactivateSchool = (code) => useAction(async () => (await saApi.post(`/tenants/${code}/reactivate`)).data.data)
export const useExtendSchool = (code) => useAction(async (date) => (await saApi.post(`/tenants/${code}/extend`, { subscription_ends_at: date })).data.data)

// The first error message of a failed request: a field error, the server's message, or the generic text.
export const errorOf = (err, fallback) => {
  const fields = err.response?.data?.errors
  if (fields) return Object.values(fields)[0]?.[0] ?? fallback
  return err.response?.data?.message ?? fallback
}
export const fieldErrors = (err) => err.response?.data?.errors ?? {}
