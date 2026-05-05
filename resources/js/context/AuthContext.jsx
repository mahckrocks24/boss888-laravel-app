import { createContext, useContext, useState, useEffect, useCallback } from 'react'
import { auth as authApi, workspace as wsApi } from '../services/api'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser]             = useState(() => {
    try { return JSON.parse(localStorage.getItem('lu_user') || 'null') } catch { return null }
  })
  const [workspace, setWorkspace]   = useState(null)
  const [workspaces, setWorkspaces] = useState([])   // MEDIUM-02: all workspaces
  const [capabilities, setCaps]     = useState(null)
  const [loading, setLoading]       = useState(true)
  const [token, setToken]           = useState(() => localStorage.getItem('lu_token') || null)

  const loadWorkspace = useCallback(async () => {
    if (!token) return
    try {
      const [wsRes, capsRes] = await Promise.all([
        wsApi.list(),
        wsApi.capabilities(),
      ])
      if (wsRes?.ok && wsRes.data?.workspaces?.length) {
        setWorkspaces(wsRes.data.workspaces)
        setWorkspace(wsRes.data.workspaces[0])
      }
      if (capsRes?.ok) {
        setCaps(capsRes.data)
      }
    } catch {}
  }, [token])

  useEffect(() => {
    if (!token) { setLoading(false); return }
    authApi.me().then(res => {
      if (res?.ok) {
        setUser(res.data.user)
        localStorage.setItem('lu_user', JSON.stringify(res.data.user))
        loadWorkspace()
      } else {
        logout()
      }
      setLoading(false)
    })
  }, [token, loadWorkspace])

  const login = async (email, password) => {
    const res = await authApi.login(email, password)
    if (res?.ok && res.data?.access_token) {
      localStorage.setItem('lu_token', res.data.access_token)
      localStorage.setItem('lu_user', JSON.stringify(res.data.user))
      setToken(res.data.access_token)
      setUser(res.data.user)
      await loadWorkspace()
      return { success: true }
    }
    return { success: false, error: res?.data?.message || res?.data?.error || 'Login failed' }
  }

  const register = async (data) => {
    const res = await authApi.register(data)
    if (res?.ok && res.data?.access_token) {
      localStorage.setItem('lu_token', res.data.access_token)
      localStorage.setItem('lu_user', JSON.stringify(res.data.user))
      setToken(res.data.access_token)
      setUser(res.data.user)
      await loadWorkspace()
      return { success: true }
    }
    return { success: false, error: res?.data?.message || res?.data?.error || 'Registration failed' }
  }

  // MEDIUM-02 FIX: Workspace switcher — calls /auth/switch-workspace then reloads capabilities
  const switchWorkspace = async (wsId) => {
    const res = await authApi.switch(wsId)
    if (res?.ok && res.data?.access_token) {
      localStorage.setItem('lu_token', res.data.access_token)
      setToken(res.data.access_token)
      await loadWorkspace()
      return { success: true }
    }
    return { success: false }
  }

  const logout = () => {
    authApi.logout().catch(() => {})
    localStorage.removeItem('lu_token')
    localStorage.removeItem('lu_user')
    setToken(null)
    setUser(null)
    setWorkspace(null)
    setWorkspaces([])
    setCaps(null)
  }

  const refreshCaps = () => loadWorkspace()

  const planSlug  = capabilities?.plan || workspace?.plan || 'free'
  const hasAI     = ['growth', 'pro', 'agency', 'ai-lite'].includes(planSlug)
  const hasFullAI = ['growth', 'pro', 'agency'].includes(planSlug)
  const hasApp888 = ['pro', 'agency'].includes(planSlug)

  return (
    <AuthContext.Provider value={{
      user, workspace, workspaces, capabilities, loading, token,
      login, register, logout, switchWorkspace, refreshCaps,
      planSlug, hasAI, hasFullAI, hasApp888,
      isAuthenticated: !!token && !!user,
    }}>
      {children}
    </AuthContext.Provider>
  )
}

export const useAuth = () => useContext(AuthContext)
