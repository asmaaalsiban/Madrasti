import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { makeResourceApi } from '../api/resource'

export function useResource(path) {
  const client = useQueryClient()
  const resource = makeResourceApi(path)
  const invalidate = () => client.invalidateQueries({ queryKey: [path] })

  return {
    useList: (params, options = {}) =>
      useQuery({ queryKey: [path, params], queryFn: () => resource.list(params), placeholderData: keepPreviousData, ...options }),
    save: useMutation({
      mutationFn: ({ id, body }) => (id ? resource.update(id, body) : resource.create(body)),
      onSuccess: invalidate,
    }),
    remove: useMutation({ mutationFn: (id) => resource.remove(id), onSuccess: invalidate }),
  }
}
