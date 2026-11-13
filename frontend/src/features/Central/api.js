import { api, setToken } from '../../api/client'

// Registers a new school and its first admin (central API).
export const registerSchool = (payload) => api.post('/central/register', payload).then((r) => r.data.data)

// Is public registration switched on? (the server decides; the website hides its "register" links while it is off)
export const registrationEnabled = () => api.get('/central/registration').then((r) => r.data.enabled === true)

// Signs the new admin in to the school that is already selected (X-School is added by the client).
export const loginAdmin = async (login, password) => {
  const { data } = await api.post('/auth/login', { login, password, device_name: 'web' })
  setToken(data.token)
}

// Does a school with this code exist? The code is sent explicitly because it is not selected yet.
export const schoolExists = async (code) => {
  try {
    await api.get('/school', { headers: { 'X-School': code } })
    return true
  } catch (err) {
    if (err.response?.status === 404) return false
    throw err
  }
}
