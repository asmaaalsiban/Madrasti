import axios from 'axios'
import i18n from '../i18n'

export const TOKEN_KEY = 'token'
export const getToken = () => { try { return localStorage.getItem(TOKEN_KEY) } catch { return null } }
export const setToken = (t) => { try { t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY) } catch { /* ignore */ } }

// Dev: call Laravel directly on the same hostname (school1.localhost -> school1.localhost:8000),
// so the school is resolved from the real Host header. Production: same-origin /api.
const API_URL = import.meta.env.VITE_API_URL
  || (import.meta.env.DEV ? `${window.location.protocol}//${window.location.hostname}:8000/api` : '/api')

// The single API client for the whole app.
export const api = axios.create({
  baseURL: API_URL,
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  config.headers['Accept-Language'] = i18n.language
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
