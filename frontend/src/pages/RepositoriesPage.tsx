import { useQuery } from '@tanstack/react-query'
import { FolderGit2, GitBranch, Lock, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { WebhookBadge } from '../components/git/badges'
import { Card, EmptyState, ErrorBanner, Input, LoadingBlock, PageHeader, Select, StatusBadge, Table, Td } from '../components/ui'
import { api } from '../lib/api'
import { useAuth } from '../lib/auth'
import { timeAgo } from '../lib/format'
import type { Paginated, Repository } from '../lib/types'

export function RepositoriesPage() {
  const { can } = useAuth()
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [status, setStatus] = useState('')

  useEffect(() => {
    const handle = setTimeout(() => setDebounced(search), 250)
    return () => clearTimeout(handle)
  }, [search])

  const query = { search: debounced, connection_status: status }
  const repositories = useQuery({
    queryKey: ['repositories', 'all', query],
    queryFn: () => api<Paginated<Repository>>('/repositories', { query }),
    placeholderData: (previous) => previous,
  })

  return (
    <>
      <PageHeader
        title="Repositories"
        description="Every repository registered across your projects and how the platform reaches it."
      />
      {can('integrations.manage') && (
        <p className="-mt-3 mb-4 text-sm text-slate-500">
          Git provider accounts are managed in{' '}
          <Link to="/settings#integrations" className="font-medium text-brand-700 hover:underline">
            Settings → Integrations
          </Link>
          .
        </p>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
          <div className="relative min-w-56 flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search repositories" className="pl-9" aria-label="Search repositories" />
          </div>
          <Select
            value={status}
            onChange={(e) => setStatus(e.target.value)}
            options={['connected', 'not_connected', 'error']}
            placeholder="All connections"
            className="w-48"
            aria-label="Filter by connection"
          />
        </div>

        {repositories.isPending ? (
          <LoadingBlock />
        ) : repositories.isError ? (
          <div className="p-5">
            <ErrorBanner error={repositories.error} />
          </div>
        ) : repositories.data.data.length === 0 ? (
          <EmptyState icon={<FolderGit2 className="size-5" />} title="No repositories" description="Repositories are added from a project's Repositories tab." />
        ) : (
          <Table head={['Repository', 'Project', 'Default branch', 'Connection', 'Webhook', 'Open PRs', 'Last push']}>
            {repositories.data.data.map((repo) => (
              <tr key={repo.id}>
                <Td>
                  <div className="flex items-center gap-1.5">
                    <a href={repo.url} target="_blank" rel="noreferrer" className="font-mono text-xs font-medium text-slate-800 hover:text-brand-700">
                      {repo.full_name ?? repo.url}
                    </a>
                    {repo.is_private && <Lock className="size-3 text-slate-400" aria-label="Private" />}
                  </div>
                  {repo.git_connection && <div className="text-xs text-slate-500">via {repo.git_connection.name}</div>}
                </Td>
                <Td>
                  {repo.project && (
                    <Link to={`/projects/${repo.project.id}?tab=repositories`} className="text-slate-700 hover:text-brand-700">
                      {repo.project.name}
                    </Link>
                  )}
                </Td>
                <Td>
                  <span className="inline-flex items-center gap-1.5 font-mono text-xs text-slate-600">
                    <GitBranch className="size-3.5 text-slate-400" /> {repo.default_branch}
                  </span>
                </Td>
                <Td>
                  <span title={repo.connection_error ?? undefined}>
                    <StatusBadge status={repo.connection_status} />
                  </span>
                </Td>
                <Td>{repo.connection_status === 'connected' ? <WebhookBadge repository={repo} /> : <span className="text-slate-400">—</span>}</Td>
                <Td className="text-slate-600">{repo.open_pull_requests_count ?? 0}</Td>
                <Td className="whitespace-nowrap text-slate-500">{timeAgo(repo.last_pushed_at)}</Td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </>
  )
}
