import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Boxes, GitBranch, Plus, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { ProjectForm } from '../components/ProjectForm'
import { Button, Card, EmptyState, ErrorBanner, Input, LoadingBlock, Modal, PageHeader, Select, StatusBadge, Table, Td } from '../components/ui'
import { api } from '../lib/api'
import { useAuth } from '../lib/auth'
import { timeAgo } from '../lib/format'
import { useMeta } from '../lib/queries'
import type { Paginated, Project } from '../lib/types'

export function ProjectsPage() {
  const { can } = useAuth()
  const meta = useMeta()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const status = params.get('status') ?? ''
  const page = Number(params.get('page') ?? 1)
  const creating = params.get('new') === '1'

  // Debounce the search box into the URL.
  useEffect(() => {
    const handle = setTimeout(() => {
      if ((params.get('search') ?? '') === search) return
      const next = new URLSearchParams(params)
      if (search) next.set('search', search)
      else next.delete('search')
      next.delete('page')
      setParams(next, { replace: true })
    }, 250)
    return () => clearTimeout(handle)
  }, [search, params, setParams])

  const query = { search: params.get('search') ?? '', status, page, per_page: 20 }
  const projects = useQuery({
    queryKey: ['projects', query],
    queryFn: () => api<Paginated<Project>>('/projects', { query }),
    placeholderData: (previous) => previous,
  })

  const setParam = (key: string, value: string | null) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next)
  }

  return (
    <>
      <PageHeader
        title="Projects"
        description="Software your agents maintain: repositories, environments and infrastructure."
        actions={
          can('projects.create') && (
            <Button icon={<Plus className="size-4" />} onClick={() => setParam('new', '1')}>
              New project
            </Button>
          )
        }
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
          <div className="relative min-w-56 flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search projects" className="pl-9" aria-label="Search projects" />
          </div>
          <Select
            value={status}
            onChange={(e) => setParam('status', e.target.value)}
            options={meta.data?.enums.project_status ?? []}
            placeholder="All statuses"
            className="w-44"
            aria-label="Filter by status"
          />
        </div>

        {projects.isPending ? (
          <LoadingBlock />
        ) : projects.isError ? (
          <div className="p-5">
            <ErrorBanner error={projects.error} />
          </div>
        ) : projects.data.data.length === 0 ? (
          <EmptyState
            icon={<Boxes className="size-5" />}
            title={query.search || status ? 'No matching projects' : 'No projects yet'}
            description={query.search || status ? 'Try a different search or filter.' : 'Register your first project to get started.'}
            action={
              can('projects.create') && !query.search && !status && (
                <Button icon={<Plus className="size-4" />} onClick={() => setParam('new', '1')}>
                  New project
                </Button>
              )
            }
          />
        ) : (
          <>
            <Table head={['Project', 'Stack', 'Repository', 'Environments', 'Status', 'Updated']}>
              {projects.data.data.map((project) => (
                <tr key={project.id} className="cursor-pointer hover:bg-slate-50" onClick={() => navigate(`/projects/${project.id}`)}>
                  <Td>
                    <Link to={`/projects/${project.id}`} className="font-medium text-slate-900 hover:text-brand-700" onClick={(e) => e.stopPropagation()}>
                      {project.name}
                    </Link>
                    {project.description && <div className="max-w-xs truncate text-xs text-slate-500">{project.description}</div>}
                  </Td>
                  <Td className="text-slate-600">{[project.framework, project.language].filter(Boolean).join(' · ') || '—'}</Td>
                  <Td>
                    {project.primary_repository ? (
                      <span className="inline-flex items-center gap-1.5 font-mono text-xs whitespace-nowrap text-slate-600">
                        <GitBranch className="size-3.5 text-slate-400" />
                        {project.primary_repository.full_name ?? project.primary_repository.url}
                      </span>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </Td>
                  <Td className="text-slate-600">{project.counts.environments ?? 0}</Td>
                  <Td>
                    <StatusBadge status={project.status} />
                  </Td>
                  <Td className="whitespace-nowrap text-slate-500">{timeAgo(project.updated_at)}</Td>
                </tr>
              ))}
            </Table>
            {projects.data.meta.last_page > 1 && (
              <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500">
                <span>
                  Page {projects.data.meta.current_page} of {projects.data.meta.last_page} · {projects.data.meta.total} projects
                </span>
                <div className="flex gap-2">
                  <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setParam('page', String(page - 1))}>
                    Previous
                  </Button>
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={page >= projects.data.meta.last_page}
                    onClick={() => setParam('page', String(page + 1))}
                  >
                    Next
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </Card>

      <Modal
        open={creating && can('projects.create')}
        onClose={() => setParam('new', null)}
        title="New project"
        description="Register a project so agents understand its repository, stack and environments."
        size="lg"
      >
        <ProjectForm
          submitLabel="Create project"
          onCancel={() => setParam('new', null)}
          onSubmit={async (payload) => {
            const { data } = await api<{ data: Project }>('/projects', { method: 'POST', body: payload })
            await queryClient.invalidateQueries({ queryKey: ['projects'] })
            await queryClient.invalidateQueries({ queryKey: ['overview'] })
            navigate(`/projects/${data.id}`)
          }}
        />
      </Modal>
    </>
  )
}
