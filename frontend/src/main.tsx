import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StrictMode, type ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, Link, Navigate, Outlet, RouterProvider } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { LoadingBlock } from './components/ui'
import './index.css'
import { ApiError } from './lib/api'
import { AuthProvider, useAuth } from './lib/auth'
import { AuditLogsPage } from './pages/AuditLogsPage'
import { GitHubCallbackPage } from './pages/GitHubCallbackPage'
import { LoginPage, RegisterPage } from './pages/AuthPages'
import { OnboardingPage } from './pages/OnboardingPage'
import { OverviewPage } from './pages/OverviewPage'
import { PlannedPage } from './pages/PlannedPage'
import { ProjectDetailPage } from './pages/ProjectDetailPage'
import { ProjectsPage } from './pages/ProjectsPage'
import { PullRequestsPage } from './pages/PullRequestsPage'
import { RepositoriesPage } from './pages/RepositoriesPage'
import { SettingsPage } from './pages/SettingsPage'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 15_000,
      refetchOnWindowFocus: false,
      // Client errors (403/404/422) will not succeed on retry.
      retry: (count, error) => count < 2 && !(error instanceof ApiError && error.status >= 400 && error.status < 500),
    },
  },
})

function RequireGuest({ children }: { children: ReactNode }) {
  const { status } = useAuth()
  if (status === 'loading') return <LoadingBlock />
  return status === 'authenticated' ? <Navigate to="/" replace /> : children
}

function RequireAuth() {
  const { status } = useAuth()
  if (status === 'loading') return <LoadingBlock />
  return status === 'authenticated' ? <Outlet /> : <Navigate to="/login" replace />
}

/** Tenant pages need an organization; new users are sent to onboarding first. */
function RequireOrganization() {
  const { organization } = useAuth()
  return organization ? <Outlet /> : <Navigate to="/onboarding" replace />
}

function RequirePermission({ permission, children }: { permission: Parameters<ReturnType<typeof useAuth>['can']>[0]; children: ReactNode }) {
  const { can } = useAuth()
  return can(permission) ? children : <Navigate to="/" replace />
}

function NotFound() {
  return (
    <div className="flex min-h-full flex-col items-center justify-center gap-2 py-24 text-center">
      <p className="text-sm font-semibold text-brand-600">404</p>
      <h1 className="text-xl font-semibold text-slate-900">Page not found</h1>
      <Link to="/" className="text-sm text-slate-500 hover:text-slate-800">
        Back to overview
      </Link>
    </div>
  )
}

const router = createBrowserRouter([
  { path: '/login', element: <RequireGuest><LoginPage /></RequireGuest> },
  { path: '/register', element: <RequireGuest><RegisterPage /></RequireGuest> },
  {
    element: <RequireAuth />,
    children: [
      { path: '/onboarding', element: <OnboardingPage /> },
      {
        element: <RequireOrganization />,
        children: [
          {
            element: <AppShell />,
            children: [
              { index: true, element: <OverviewPage /> },
              { path: 'projects', element: <ProjectsPage /> },
              { path: 'projects/:projectId', element: <ProjectDetailPage /> },
              { path: 'repositories', element: <RepositoriesPage /> },
              { path: 'pull-requests', element: <PullRequestsPage /> },
              { path: 'integrations/github/callback', element: <RequirePermission permission="integrations.manage"><GitHubCallbackPage /></RequirePermission> },
              { path: 'audit-logs', element: <RequirePermission permission="audit.view"><AuditLogsPage /></RequirePermission> },
              { path: 'settings', element: <SettingsPage /> },
              { path: 'planned/:feature', element: <PlannedPage /> },
              { path: '*', element: <NotFound /> },
            ],
          },
        ],
      },
    ],
  },
])

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <RouterProvider router={router} />
      </AuthProvider>
    </QueryClientProvider>
  </StrictMode>,
)
