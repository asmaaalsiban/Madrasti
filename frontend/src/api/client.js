import axios from 'axios'
import i18n from '../i18n'
import { getSchool } from '../tenant/school'

// One token per school, so switching school never sends another school's token.
export const tokenKey = () => `token:${getSchool() ?? ''}`
export const getToken = () => { try { return getSchool() ? localStorage.getItem(tokenKey()) : null } catch { return null } }
export const setToken = (t) => {
  if (!getSchool()) return
  try {
    if (t) localStorage.setItem(tokenKey(), t)
    else localStorage.removeItem(tokenKey())
  } catch { /* ignore */ }
}

// Same origin: the Vite dev proxy and the production web server forward /api to Laravel.
const API_URL = import.meta.env.VITE_API_URL || '/api'

// The single API client for the whole app.
export const api = axios.create({
  baseURL: API_URL,
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  config.headers['Accept-Language'] = i18n.language
  const school = getSchool()
  if (school) config.headers['X-School'] = school
  return config
})

api.interceptors.response.use(
  (r) => r,
  (error) => {
    if (error.response?.status === 401 && getToken()) {
      setToken(null)
      window.dispatchEvent(new Event('auth:expired'))
    }
    return Promise.reject(error)
  },
)
