import { api } from '../../api/client'

// Registers a new school and its first admin on the central domain.
export const registerSchool = (payload) => api.post('/central/register', payload).then((r) => r.data.data)
