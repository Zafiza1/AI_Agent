import { useQuery } from '@tanstack/react-query'
import { ArrowRight, Boxes, Layers, Plus, Server, ShieldCheck, Users } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ActivityList } from '../components/ActivityList'
import { Button, Card, CardHeader, EmptyState, ErrorBanner, LoadingBlock, PageHeader, StatusBadge } from '../components/ui'
import { api } from '../lib/api'
import { useAuth } from '../lib/auth'
import { titleCase } from '../lib/format'
import type { Overview, Paginated, Project, ResourceStatus, SystemHealth } from '../lib/types'

function Stat({ label, value, detail, icon }: { label: string; value: ReactNode; detail?: ReactNode; icon: ReactNode }) {
  return (
    <Card className="p-5">
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-slate-500">{label}</span>
        <span className="text-slate-400">{icon}</span>
      </div>
      <div className="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{value}</div>
      {detail && <div className="mt-1 text-xs text-slate-500">{detail}</div>}
    </Card>
  )
}

const plural = (count: number, noun: string) => `${count} ${noun}${count === 1 ? '' : 's'}`

const healthOrder: ResourceStatus[] = ['healthy', 'warning', 'critical', 'offline', 'unknown']
const healthBar: Record<ResourceStatus, string> = {
  healthy: 'bg-emerald-500',
  warning: 'bg-amber-500',
  critical: 'bg-red-500',
  offline: 'bg-red-300',
  unknown: 'bg-slate-300',
}

export function OverviewPage() {
  const { organization, user, can } = useAuth()

  const overview = useQuery({
    queryKey: ['overview'],
    queryFn: () => api<{ data: Overview }>('/dashboard/overview').then((r) => r.data),
  })
  const projects = useQuery({
    queryKey: ['projects', { per_page: 5 }],
    queryFn: () => api<Paginated<Project>>('/projects', { query: { per_page: 5 } }),
  })
  const system = useQuery({
    queryKey: ['system-health'],
    queryFn: () => api<{ data: SystemHealth }>('/system/health', { tenant: false }).then((r) => r.data),
    refetchInterval: 30_000,
  })

  if (overview.isPending) return <LoadingBlock />
  if (overview.isError) return <ErrorBanner error={overview.error} />

  const data = overview.data
  const totalResources = data.infrastructure.servers + data.infrastructure.databases + data.infrastructure.services

  return (
    <>
      <PageHeader
        eyebrow={organization?.name}
        title={`Good to see you, ${user?.name.split(' ')[0]}`}
        description="Health of your projects and the platform at a glance."
        actions={
          can('projects.create') && (
            <Link to="/projects?new=1">
              <Button icon={<Plus className="size-4" />}>New project</Button>
            </Link>
          )
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat
          label="Projects"
          value={data.projects.total}
          icon={<Boxes className="size-4" />}
          detail={Object.entries(data.projects.by_status).map(([s, n]) => `${n} ${s}`).join(' · ') || 'No projects yet'}
        />
        <Stat
          label="Environments"
          value={data.environments.total}
          icon={<Layers className="size-4" />}
          detail={`${data.environments.protected} protected (approval-gated)`}
        />
        <Stat
          label="Infrastructure"
          value={totalResources}
          icon={<Server className="size-4" />}
          detail={[
            plural(data.infrastructure.servers, 'server'),
            plural(data.infrastructure.databases, 'database'),
            plural(data.infrastructure.services, 'service'),
          ].join(' · ')}
        />
        <Stat label="Members" value={data.members} icon={<Users className="size-4" />} detail={`You are ${organization?.role}`} />
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card>
            <CardHeader
              title="Projects"
              description="Most recently registered projects"
              actions={
                <Link to="/projects" className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                  View all <ArrowRight className="size-3" />
                </Link>
              }
            />
            {projects.isPending ? (
              <LoadingBlock />
            ) : projects.data?.data.length ? (
              <ul className="divide-y divide-slate-100">
                {projects.data.data.map((project) => (
                  <li key={project.id}>
                    <Link to={`/projects/${project.id}`} className="flex items-center gap-4 px-5 py-3 hover:bg-slate-50">
                      <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-medium text-slate-900">{project.name}</div>
                        <div className="truncate text-xs text-slate-500">
                          {[project.framework, project.language, project.database_type].filter(Boolean).join(' · ') || 'Stack not set'}
                        </div>
                      </div>
                      <StatusBadge status={project.status} />
                    </Link>
                  </li>
                ))}
              </ul>
            ) : (
              <EmptyState
                icon={<Boxes className="size-5" />}
                title="No projects yet"
                description="Register a project to give agents its repository, environments and infrastructure context."
              />
            )}
          </Card>

          {can('audit.view') && (
            <Card>
              <CardHeader
                title="Recent activity"
                description="Latest audited actions in this organization"
                actions={
                  <Link to="/audit-logs" className="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                    Audit log <ArrowRight className="size-3" />
                  </Link>
                }
              />
              {data.recent_activity.length ? <ActivityList logs={data.recent_activity} /> : <EmptyState title="No activity yet" />}
            </Card>
          )}
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader title="Platform status" description="Control plane dependencies" />
            <div className="space-y-3 px-5 py-4">
              {system.isPending ? (
                <LoadingBlock />
              ) : system.isError ? (
                <ErrorBanner error={system.error} />
              ) : (
                (['database', 'redis', 'agent'] as const).map((name) => (
                  <div key={name} className="flex items-center justify-between text-sm">
                    <span className="text-slate-700">{name === 'agent' ? 'Agent engine' : titleCase(name)}</span>
                    <span className="flex items-center gap-2">
                      <span className="font-mono text-xs text-slate-400">{system.data.checks[name].latency_ms} ms</span>
                      <StatusBadge status={system.data.checks[name].status} label={system.data.checks[name].status === 'up' ? 'Operational' : 'Down'} />
                    </span>
                  </div>
                ))
              )}
            </div>
          </Card>

          <Card>
            <CardHeader title="Infrastructure health" description="Last observed status of registered resources" />
            <div className="px-5 py-4">
              {totalResources === 0 ? (
                <p className="text-sm text-slate-500">No servers, databases or services registered yet.</p>
              ) : (
                <>
                  <div className="flex h-2 overflow-hidden rounded-full bg-slate-100">
                    {healthOrder.map((status) => {
                      const count = data.infrastructure.health[status] ?? 0
                      return count ? <div key={status} className={healthBar[status]} style={{ width: `${(count / totalResources) * 100}%` }} /> : null
                    })}
                  </div>
                  <ul className="mt-4 space-y-2">
                    {healthOrder.map((status) => (
                      <li key={status} className="flex items-center justify-between text-sm">
                        <span className="flex items-center gap-2 text-slate-600">
                          <span className={`size-2 rounded-full ${healthBar[status]}`} /> {titleCase(status)}
                        </span>
                        <span className="font-medium text-slate-900">{data.infrastructure.health[status] ?? 0}</span>
                      </li>
                    ))}
                  </ul>
                  <p className="mt-4 text-xs text-slate-500">Live health checks start with monitoring in Phase 7.</p>
                </>
              )}
            </div>
          </Card>

          <Card className="p-5">
            <div className="flex items-start gap-3">
              <span className="rounded-lg bg-violet-50 p-2 text-violet-600">
                <ShieldCheck className="size-4" />
              </span>
              <div>
                <h3 className="text-sm font-semibold text-slate-900">Safety defaults</h3>
                <p className="mt-1 text-xs leading-relaxed text-slate-500">
                  Production environments are protected and require approval. Secrets are write-only and never sent to AI models.
                  Every change is recorded in the audit log.
                </p>
              </div>
            </div>
          </Card>
        </div>
      </div>
    </>
  )
}
