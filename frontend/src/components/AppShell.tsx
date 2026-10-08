import clsx from 'clsx'
import {
  Bot,
  Check,
  ChevronsUpDown,
  CircleCheck,
  FolderGit2,
  GitPullRequest,
  LayoutDashboard,
  ListChecks,
  LogOut,
  Menu,
  Plus,
  Rocket,
  ScrollText,
  Server,
  Settings,
  ShieldAlert,
  Siren,
  Activity,
  Boxes,
  type LucideIcon,
} from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../lib/auth'
import { initials, titleCase } from '../lib/format'
import { roadmapItem } from '../lib/roadmap'
import type { Permission } from '../lib/types'

interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  permission?: Permission
  phase?: number
}

const sections: { title?: string; items: NavItem[] }[] = [
  {
    items: [
      { to: '/', label: 'Overview', icon: LayoutDashboard },
      { to: '/projects', label: 'Projects', icon: Boxes },
    ],
  },
  {
    title: 'AI operations',
    items: [
      { to: '/planned/tasks', label: 'Tasks', icon: ListChecks },
      { to: '/planned/agents', label: 'AI Agents', icon: Bot },
      { to: '/planned/approvals', label: 'Approvals', icon: CircleCheck },
      { to: '/planned/incidents', label: 'Incidents', icon: Siren },
    ],
  },
  {
    title: 'Delivery',
    items: [
      { to: '/repositories', label: 'Repositories', icon: FolderGit2 },
      { to: '/pull-requests', label: 'Pull Requests', icon: GitPullRequest },
      { to: '/planned/deployments', label: 'Deployments', icon: Rocket },
    ],
  },
  {
    title: 'Infrastructure',
    items: [
      { to: '/planned/servers', label: 'Servers', icon: Server },
      { to: '/planned/monitoring', label: 'Monitoring', icon: Activity },
    ],
  },
  {
    title: 'Governance',
    items: [
      { to: '/audit-logs', label: 'Audit Logs', icon: ScrollText, permission: 'audit.view' },
      { to: '/settings', label: 'Settings', icon: Settings },
    ],
  },
]

function phaseOf(item: NavItem): number | undefined {
  return item.to.startsWith('/planned/') ? roadmapItem(item.to.slice('/planned/'.length))?.phase : undefined
}

export function AppShell() {
  const location = useLocation()
  // The mobile drawer belongs to the page it was opened on, so navigating closes it.
  const [openedOn, setOpenedOn] = useState<string | null>(null)
  const mobileOpen = openedOn === location.pathname
  const setMobileOpen = (open: boolean) => setOpenedOn(open ? location.pathname : null)

  return (
    <div className="flex h-full">
      <aside
        className={clsx(
          'fixed inset-y-0 left-0 z-40 w-64 shrink-0 transform bg-sidebar transition-transform lg:static lg:translate-x-0',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        )}
      >
        <Sidebar />
      </aside>
      {mobileOpen && <div className="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" onClick={() => setMobileOpen(false)} />}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-14 shrink-0 items-center gap-3 border-b border-slate-200 bg-white px-4 lg:hidden">
          <button onClick={() => setMobileOpen(true)} className="rounded-md p-1.5 text-slate-600 hover:bg-slate-100" aria-label="Open navigation">
            <Menu className="size-5" />
          </button>
          <Logo dark={false} />
        </header>
        <main className="flex-1 overflow-y-auto">
          <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}

function Logo({ dark = true }: { dark?: boolean }) {
  return (
    <Link to="/" className="flex items-center gap-2.5">
      <span className="flex size-7 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-violet-500 text-white shadow-sm">
        <ShieldAlert className="size-4" />
      </span>
      <span className={clsx('text-sm font-semibold tracking-tight', dark ? 'text-white' : 'text-slate-900')}>Maintainer</span>
    </Link>
  )
}

function Sidebar() {
  const { can } = useAuth()

  return (
    <div className="flex h-full flex-col">
      <div className="flex h-14 items-center px-5">
        <Logo />
      </div>
      <div className="px-3 pb-3">
        <OrganizationSwitcher />
      </div>
      <nav className="flex-1 space-y-5 overflow-y-auto px-3 py-2">
        {sections.map((section, i) => (
          <div key={i}>
            {section.title && (
              <div className="mb-1.5 px-2 text-[11px] font-medium tracking-wider text-slate-500 uppercase">{section.title}</div>
            )}
            <ul className="space-y-0.5">
              {section.items
                .filter((item) => !item.permission || can(item.permission))
                .map((item) => {
                  const phase = phaseOf(item)
                  return (
                    <li key={item.to}>
                      <NavLink
                        to={item.to}
                        end={item.to === '/'}
                        className={({ isActive }) =>
                          clsx(
                            'flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm transition-colors',
                            isActive ? 'bg-sidebar-active text-white' : 'text-slate-400 hover:bg-sidebar-hover hover:text-slate-200',
                          )
                        }
                      >
                        <item.icon className="size-4 shrink-0" />
                        <span className="flex-1">{item.label}</span>
                        {phase && (
                          <span className="rounded px-1.5 py-px text-[10px] font-medium text-slate-500 ring-1 ring-slate-700">P{phase}</span>
                        )}
                      </NavLink>
                    </li>
                  )
                })}
            </ul>
          </div>
        ))}
      </nav>
      <UserMenu />
    </div>
  )
}

function OrganizationSwitcher() {
  const { organization, organizations, selectOrganization } = useAuth()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const navigate = useNavigate()

  useEffect(() => {
    if (!open) return
    const onClick = (event: MouseEvent) => {
      if (!ref.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [open])

  return (
    <div ref={ref} className="relative">
      <button
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center gap-2.5 rounded-lg bg-sidebar-hover px-2.5 py-2 text-left ring-1 ring-white/5 hover:bg-sidebar-active"
      >
        <span className="flex size-7 items-center justify-center rounded-md bg-slate-700 text-xs font-semibold text-white">
          {initials(organization?.name ?? '?')}
        </span>
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-medium text-white">{organization?.name}</span>
          <span className="block text-xs text-slate-400">{organization?.role ? titleCase(organization.role) : ''}</span>
        </span>
        <ChevronsUpDown className="size-4 text-slate-500" />
      </button>

      {open && (
        <div className="absolute inset-x-0 top-full z-50 mt-1 overflow-hidden rounded-lg bg-white py-1 shadow-lg ring-1 ring-slate-200">
          {organizations.map((org) => (
            <button
              key={org.id}
              onClick={() => {
                selectOrganization(org.id)
                setOpen(false)
                navigate('/')
              }}
              className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
            >
              <span className="flex-1 truncate">{org.name}</span>
              {org.id === organization?.id && <Check className="size-4 text-brand-600" />}
            </button>
          ))}
          <div className="my-1 border-t border-slate-100" />
          <Link
            to="/onboarding"
            onClick={() => setOpen(false)}
            className="flex items-center gap-2 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
          >
            <Plus className="size-4" /> New organization
          </Link>
        </div>
      )}
    </div>
  )
}

function UserMenu() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  return (
    <div className="flex items-center gap-2.5 border-t border-white/5 px-4 py-3">
      <span className="flex size-8 items-center justify-center rounded-full bg-slate-700 text-xs font-semibold text-white">
        {initials(user?.name ?? '')}
      </span>
      <div className="min-w-0 flex-1">
        <div className="truncate text-sm font-medium text-slate-200">{user?.name}</div>
        <div className="truncate text-xs text-slate-500">{user?.email}</div>
      </div>
      <button
        onClick={async () => {
          await logout()
          navigate('/login')
        }}
        className="rounded-md p-1.5 text-slate-500 hover:bg-sidebar-hover hover:text-slate-200"
        aria-label="Sign out"
        title="Sign out"
      >
        <LogOut className="size-4" />
      </button>
    </div>
  )
}
