import { useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowLeft, Brain, ExternalLink, GitBranch, Lock } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ActivityList } from '../components/ActivityList'
import { ProjectForm } from '../components/ProjectForm'
import { Badge, Button, Card, CardHeader, ConfirmDialog, EmptyState, ErrorBanner, LoadingBlock, PageHeader, StatusBadge } from '../components/ui'
import { api } from '../lib/api'
import { useAuth } from '../lib/auth'
import { formatDateTime, titleCase } from '../lib/format'
import type { AuditLog, Paginated, Project } from '../lib/types'
import { EnvironmentsTab } from './project/EnvironmentsTab'
import { InfrastructureTab } from './project/InfrastructureTab'
import { RepositoriesTab } from './project/RepositoriesTab'

const tabs = [
  { id: 'overview', label: 'Overview' },
  { id: 'environments', label: 'Environments' },
  { id: 'infrastructure', label: 'Infrastructure' },
  { id: 'repositories', label: 'Repositories' },
  { id: 'settings', label: 'Settings' },
] as const

const plannedTabs = [
  { label: 'Tasks', phase: 3 },
  { label: 'Deployments', phase: 6 },
  { label: 'Incidents', phase: 7 },
  { label: 'Monitoring', phase: 7 },
]

type TabId = (typeof tabs)[number]['id']

export function ProjectDetailPage() {
  const { projectId } = useParams()
  const [params, setParams] = useSearchParams()
  const tab = (tabs.find((t) => t.id === params.get('tab'))?.id ?? 'overview') as TabId

  const project = useQuery({
    queryKey: ['project', projectId],
    queryFn: () => api<{ data: Project }>(`/projects/${projectId}`).then((r) => r.data),
  })

  if (project.isPending) return <LoadingBlock />
  if (project.isError) {
    return (
      <div className="space-y-4">
        <BackLink />
        <ErrorBanner error={project.error} />
      </div>
    )
  }

  const p = project.data

  return (
    <>
      <BackLink />
      <PageHeader
        title={
          <span className="flex items-center gap-3">
            {p.name} <StatusBadge status={p.status} />
          </span>
        }
        description={p.description ?? undefined}
        actions={
          p.repository_url && (
            <a href={p.repository_url} target="_blank" rel="noreferrer">
              <Button variant="secondary" icon={<ExternalLink className="size-4" />}>
                Repository
              </Button>
            </a>
          )
        }
      />

      <div className="mb-6 overflow-x-auto border-b border-slate-200">
        <nav className="-mb-px flex gap-6 text-sm">
          {tabs.map((t) => (
            <button
              key={t.id}
              onClick={() => setParams(t.id === 'overview' ? {} : { tab: t.id })}
              className={clsx(
                'border-b-2 pb-3 font-medium whitespace-nowrap transition-colors',
                tab === t.id ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800',
              )}
            >
              {t.label}
            </button>
          ))}
          {plannedTabs.map((t) => (
            <span key={t.label} className="flex cursor-not-allowed items-center gap-1.5 border-b-2 border-transparent pb-3 whitespace-nowrap text-slate-300" title={`Available in Phase ${t.phase}`}>
              {t.label}
              <span className="rounded px-1 text-[10px] ring-1 ring-slate-200">P{t.phase}</span>
            </span>
          ))}
        </nav>
      </div>

      {tab === 'overview' && <OverviewTab project={p} />}
      {tab === 'environments' && <EnvironmentsTab project={p} />}
      {tab === 'infrastructure' && <InfrastructureTab project={p} />}
      {tab === 'repositories' && <RepositoriesTab project={p} />}
      {tab === 'settings' && <SettingsTab project={p} />}
    </>
  )
}

function BackLink() {
  return (
    <Link to="/projects" className="mb-3 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-800">
      <ArrowLeft className="size-3.5" /> Projects
    </Link>
  )
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium text-slate-500">{label}</dt>
      <dd className="mt-1 text-sm text-slate-900">{children || <span className="text-slate-400">Not set</span>}</dd>
    </div>
  )
}

function OverviewTab({ project }: { project: Project }) {
  const { can } = useAuth()
  const activity = useQuery({
    queryKey: ['audit-logs', { project_id: project.id, per_page: 8 }],
    queryFn: () => api<Paginated<AuditLog>>('/audit-logs', { query: { project_id: project.id, per_page: 8 } }),
    enabled: can('audit.view'),
  })

  return (
    <div className="grid gap-6 lg:grid-cols-3">
      <div className="space-y-6 lg:col-span-2">
        <Card>
          <CardHeader title="Stack" />
          <dl className="grid gap-5 px-5 py-4 sm:grid-cols-3">
            <Detail label="Framework">{project.framework}</Detail>
            <Detail label="Language">{project.language}</Detail>
            <Detail label="Database">{project.database_type && titleCase(project.database_type)}</Detail>
            <Detail label="Deployment">{project.deployment_type}</Detail>
            <Detail label="Repository">
              {project.primary_repository && <span className="font-mono text-xs">{project.primary_repository.full_name ?? project.primary_repository.url}</span>}
            </Detail>
            <Detail label="Default branch">
              {project.default_branch && (
                <span className="inline-flex items-center gap-1 font-mono text-xs">
                  <GitBranch className="size-3.5 text-slate-400" /> {project.default_branch}
                </span>
              )}
            </Detail>
          </dl>
        </Card>

        <Card>
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <Brain className="size-4 text-violet-500" /> AI context
              </span>
            }
            description="Project memory given to every agent working on this project."
          />
          <div className="px-5 py-4">
            {project.ai_context.notes ? (
              <p className="text-sm leading-relaxed whitespace-pre-line text-slate-700">{project.ai_context.notes}</p>
            ) : (
              <p className="text-sm text-slate-500">No notes yet. Add rules such as “never modify the production database directly” in Settings.</p>
            )}
          </div>
        </Card>

        {can('audit.view') && (
          <Card>
            <CardHeader title="Activity" description="Audited changes to this project" />
            {activity.isPending ? (
              <LoadingBlock />
            ) : activity.data?.data.length ? (
              <ActivityList logs={activity.data.data} />
            ) : (
              <EmptyState title="No activity yet" />
            )}
          </Card>
        )}
      </div>

      <div className="space-y-6">
        <Card>
          <CardHeader title="Environments" />
          <ul className="divide-y divide-slate-100">
            {(project.environments ?? []).map((env) => (
              <li key={env.id} className="flex items-center justify-between px-5 py-2.5 text-sm">
                <span className="font-mono text-slate-800">{env.name}</span>
                <span className="flex items-center gap-1.5">
                  {env.is_protected && <Lock className="size-3.5 text-violet-500" aria-label="Protected" />}
                  <Badge tone={env.type === 'production' ? 'violet' : env.type === 'staging' ? 'info' : 'neutral'}>{titleCase(env.type)}</Badge>
                </span>
              </li>
            ))}
            {(project.environments ?? []).length === 0 && <li className="px-5 py-3 text-sm text-slate-500">No environments.</li>}
          </ul>
        </Card>

        <Card>
          <CardHeader title="Registry" />
          <dl className="grid grid-cols-2 gap-4 px-5 py-4">
            {(['repositories', 'servers', 'databases', 'services'] as const).map((key) => (
              <div key={key}>
                <dt className="text-xs text-slate-500">{titleCase(key)}</dt>
                <dd className="text-lg font-semibold text-slate-900">{project.counts[key] ?? 0}</dd>
              </div>
            ))}
          </dl>
        </Card>

        <Card className="px-5 py-4 text-xs text-slate-500">
          Created {formatDateTime(project.created_at)}
          {project.created_by && <> by {project.created_by.name}</>}
        </Card>
      </div>
    </div>
  )
}

function SettingsTab({ project }: { project: Project }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [saved, setSaved] = useState(false)

  return (
    <div className="max-w-3xl space-y-6">
      <Card>
        <CardHeader title="Project details" description={can('projects.update') ? undefined : 'You have read-only access to this project.'} />
        <div className="px-5 py-5">
          {can('projects.update') ? (
            <>
              {saved && <div className="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">Changes saved.</div>}
              <ProjectForm
                project={project}
                submitLabel="Save changes"
                onSubmit={async (payload) => {
                  setSaved(false)
                  await api(`/projects/${project.id}`, { method: 'PATCH', body: payload })
                  await queryClient.invalidateQueries({ queryKey: ['project', project.id] })
                  await queryClient.invalidateQueries({ queryKey: ['projects'] })
                  setSaved(true)
                }}
              />
            </>
          ) : (
            <p className="text-sm text-slate-500">Ask an organization admin for maintainer access to edit this project.</p>
          )}
        </div>
      </Card>

      {can('projects.delete') && (
        <Card className="border-red-200">
          <CardHeader title="Danger zone" />
          <div className="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
            <div>
              <p className="text-sm font-medium text-slate-900">Delete this project</p>
              <p className="text-xs text-slate-500">The project is archived (soft-deleted) and disappears from the dashboard. This is audited.</p>
            </div>
            <Button variant="danger" onClick={() => setConfirmDelete(true)}>
              Delete project
            </Button>
          </div>
        </Card>
      )}

      <ConfirmDialog
        open={confirmDelete}
        onClose={() => setConfirmDelete(false)}
        title={`Delete ${project.name}?`}
        description={<p>Agents will no longer be able to work on this project. This action is recorded in the audit log.</p>}
        confirmLabel="Delete project"
        onConfirm={async () => {
          await api(`/projects/${project.id}`, { method: 'DELETE' })
          queryClient.removeQueries({ queryKey: ['project', project.id] })
          await queryClient.invalidateQueries({ queryKey: ['projects'] })
          navigate('/projects')
        }}
      />
    </div>
  )
}
