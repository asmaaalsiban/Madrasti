import { api } from '../../../api/client'

// Thin REST wrapper for one admin resource (e.g. 'subjects').
export const makeResourceApi = (path) => ({
  list: (params) => api.get(`/${path}`, { params }).then((r) => r.data),
  create: (body) => api.post(`/${path}`, body).then((r) => r.data),
  update: (id, body) => api.put(`/${path}/${id}`, body).then((r) => r.data),
  remove: (id) => api.delete(`/${path}/${id}`),
})
