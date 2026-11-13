import axios from 'axios'
import i18n from '../../i18n'

const KEY = 'sa-token'

// The Super Admin's token is kept apart from the schools' tokens (`token:<school>`).
export const getSaToken = () => { try { return localStorage.getItem(KEY) } catch { return null } }
export const setSaToken = (token) => {
  try {
    if (token) localStorage.setItem(KEY, token)
    else localStorage.removeItem(KEY)
  } catch { /* storage blocked: the token only lasts until the page reloads */ }
}

// The central API: its own client, never sends a school header or a school token.
const saApi = axios.create({
  baseURL: '/api/central',
  headers: { Accept: 'application/json' },
})

saApi.interceptors.request.use((config) => {
  const token = getSaToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  config.headers['Accept-Language'] = i18n.language
  return config
})

// An expired or revoked token (they last 8 hours): forget it; the layout then shows the sign-in again.
saApi.interceptors.response.use(
  (r) => r,
  (error) => {
    if (error.response?.status === 401 && getSaToken()) {
      setSaToken(null)
      window.dispatchEvent(new Event('sa:expired'))
    }
    return Promise.reject(error)
  },
)

export default saApi
