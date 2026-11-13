import axios from 'axios'
import i18n from '../i18n'
import { getSchool, goTo } from '../tenant/school'

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

const SUSPENDED_PATH = '/suspended'
export const DOWNLOAD_PATH = '/download-your-data'

// Set from the school answer: in an exit window the administrator works only on the download page.
let inExitWindow = false
export const setExitWindow = (value) => { inExitWindow = value }

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
    // The platform blocked this school: forget the token and explain, instead of showing broken screens.
    // The one exception: the administrator on the download page of a school in an exit window. A blocked background call
    // must not sign them out of the only page they are allowed to use.
    const keepForDownload = inExitWindow && window.location.pathname === DOWNLOAD_PATH
    if (error.response?.status === 403 && error.response.data?.code === 'school_suspended' && !keepForDownload) {
      setToken(null)
      window.dispatchEvent(new Event('auth:expired'))
      if (window.location.pathname !== SUSPENDED_PATH) goTo(SUSPENDED_PATH)
    }
    return Promise.reject(error)
  },
)
