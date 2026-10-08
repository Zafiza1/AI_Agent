import { useQueryClient } from '@tanstack/react-query'
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, session } from './api'
import type { Organization, Permission, User } from './types'

interface AuthPayload {
  token?: string
  user: User
  organizations: Organization[]
}

interface AuthState {
  status: 'loading' | 'authenticated' | 'anonymous'
  user: User | null
  organizations: Organization[]
  organization: Organization | null
  login: (email: string, password: string) => Promise<void>
  register: (input: { name: string; email: string; password: string; password_confirmation: string }) => Promise<void>
  logout: () => Promise<void>
  refresh: () => Promise<void>
  selectOrganization: (id: string) => void
  can: (permission: Permission) => boolean
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [status, setStatus] = useState<AuthState['status']>(session.token ? 'loading' : 'anonymous')
  const [user, setUser] = useState<User | null>(null)
  const [organizations, setOrganizations] = useState<Organization[]>([])
  const [organizationId, setOrganizationId] = useState<string | null>(session.organizationId)

  const apply = useCallback((payload: AuthPayload) => {
    if (payload.token) session.token = payload.token

    const current =
      payload.organizations.find((o) => o.id === session.organizationId) ?? payload.organizations[0] ?? null
    session.organizationId = current?.id ?? null

    setUser(payload.user)
    setOrganizations(payload.organizations)
    setOrganizationId(current?.id ?? null)
    setStatus('authenticated')
  }, [])

  const clear = useCallback(() => {
    session.token = null
    setUser(null)
    setOrganizations([])
    setStatus('anonymous')
    queryClient.clear()
  }, [queryClient])

  const refresh = useCallback(async () => {
    apply(await api<AuthPayload>('/auth/me', { tenant: false }))
  }, [apply])

  useEffect(() => {
    session.onUnauthorized(clear)
    if (session.token) refresh().catch(clear)
  }, [refresh, clear])

  const value = useMemo<AuthState>(() => {
    const organization = organizations.find((o) => o.id === organizationId) ?? null

    return {
      status,
      user,
      organizations,
      organization,
      login: async (email, password) => {
        apply(await api<AuthPayload>('/auth/login', { method: 'POST', body: { email, password }, tenant: false }))
      },
      register: async (input) => {
        apply(await api<AuthPayload>('/auth/register', { method: 'POST', body: input, tenant: false }))
      },
      logout: async () => {
        await api('/auth/logout', { method: 'POST', tenant: false }).catch(() => undefined)
        clear()
      },
      refresh,
      selectOrganization: (id) => {
        session.organizationId = id
        setOrganizationId(id)
        // Every cached query belongs to the previous organization.
        queryClient.removeQueries()
      },
      can: (permission) => organization?.permissions.includes(permission) ?? false,
    }
  }, [status, user, organizations, organizationId, apply, clear, refresh, queryClient])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>')
  return context
}
