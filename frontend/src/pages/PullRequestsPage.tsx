import { useQuery } from '@tanstack/react-query'
import { GitPullRequest, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { PullRequestTable } from '../components/git/RepositoryPanel'
import { Button, Card, EmptyState, ErrorBanner, Input, LoadingBlock, PageHeader, Select, Toggle } from '../components/ui'
import { api } from '../lib/api'
import type { Paginated, PullRequest } from '../lib/types'

export function PullRequestsPage() {
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [state, setState] = useState('open')
  const [platformOnly, setPlatformOnly] = useState(false)
  const [page, setPage] = useState(1)

  useEffect(() => {
    const handle = setTimeout(() => {
      setDebounced(search)
      setPage(1)
    }, 250)
    return () => clearTimeout(handle)
  }, [search])

  const query = { search: debounced, state, opened_via_platform: platformOnly ? 1 : undefined, page, per_page: 25 }
  const pulls = useQuery({
    queryKey: ['pull-requests', query],
    queryFn: () => api<Paginated<PullRequest>>('/pull-requests', { query }),
    placeholderData: (previous) => previous,
  })

  return (
    <>
      <PageHeader
        title="Pull Requests"
        description="Pull requests across all connected repositories, with CI status. Changes reach protected branches only through review."
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
          <div className="relative min-w-56 flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search title or branch" className="pl-9" aria-label="Search pull requests" />
          </div>
          <Select
            value={state}
            onChange={(e) => {
              setState(e.target.value)
              setPage(1)
            }}
            options={['open', 'merged', 'closed']}
            placeholder="All states"
            className="w-40"
            aria-label="Filter by state"
          />
          <Toggle
            checked={platformOnly}
            onChange={(v) => {
              setPlatformOnly(v)
              setPage(1)
            }}
            label="Opened via platform"
          />
        </div>

        {pulls.isPending ? (
          <LoadingBlock />
        ) : pulls.isError ? (
          <div className="p-5">
            <ErrorBanner error={pulls.error} />
          </div>
        ) : pulls.data.data.length === 0 ? (
          <EmptyState
            icon={<GitPullRequest className="size-5" />}
            title="No pull requests"
            description="Connect a repository to sync its pull requests. New ones arrive through webhooks."
          />
        ) : (
          <>
            <PullRequestTable pulls={pulls.data.data} showRepository />
            {pulls.data.meta.last_page > 1 && (
              <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500">
                <span>
                  Page {pulls.data.meta.current_page} of {pulls.data.meta.last_page} · {pulls.data.meta.total} pull requests
                </span>
                <div className="flex gap-2">
                  <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                    Previous
                  </Button>
                  <Button size="sm" variant="secondary" disabled={page >= pulls.data.meta.last_page} onClick={() => setPage(page + 1)}>
                    Next
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </Card>
    </>
  )
}
