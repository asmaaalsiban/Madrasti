import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api, getToken, setToken } from '../../api/client'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(Boolean(getToken()))

  useEffect(() => {
    if (!getToken()) return
    api.get('/auth/me')
      .then((r) => setUser(r.data.data))
      .catch(() => setToken(null))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    const onExpired = () => setUser(null)
    window.addEventListener('auth:expired', onExpired)
    return () => window.removeEventListener('auth:expired', onExpired)
  }, [])

  const login = useCallback(async (login, password) => {
    const { data } = await api.post('/auth/login', { login, password, device_name: 'web' })
    setToken(data.token)
    setUser(data.user)
    return data.user
  }, [])

  const logout = useCallback(async () => {
    try { await api.post('/auth/logout') } catch { /* token may already be invalid */ }
    setToken(null)
    setUser(null)
  }, [])

  // Replaces the signed-in user (e.g. after the password was changed and must_change_password is now false).
  const updateUser = useCallback((next) => setUser(next), [])

  const value = useMemo(() => ({ user, loading, login, logout, updateUser }), [user, loading, login, logout, updateUser])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export const useAuth = () => useContext(AuthContext)
