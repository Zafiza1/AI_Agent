import { useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { ExternalLink, GitBranch, GitPullRequest, Lock, Plus, ShieldCheck } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { timeAgo } from '../../lib/format'
import { useMeta } from '../../lib/queries'
import type { Branch, Commit, Paginated, PullRequest, Repository, WebhookDelivery } from '../../lib/types'
import { Badge, Button, EmptyState, ErrorBanner, Field, Input, LoadingBlock, Modal, Select, StatusBadge, Table, Td, Textarea, Toggle } from '../ui'
import { ChecksBadge, PullRequestStateBadge } from './badges'

const tabs = [
  { id: 'pulls', label: 'Pull requests' },
  { id: 'branches', label: 'Branches' },
  { id: 'commits', label: 'Commits' },
  { id: 'webhooks', label: 'Webhook deliveries' },
] as const

type Tab = (typeof tabs)[number]['id']

/**
 * Live view of a connected repository. Branch and commit data come from the
 * provider; pull requests and deliveries from the platform's own records.
 */
export function RepositoryPanel({ repository }: { repository: Repository }) {
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('pulls')
  const [modal, setModal] = useState<'branch' | 'pull' | null>(null)
  const canWrite = can('repositories.write')

  return (
    <div className="border-t border-slate-100 bg-slate-50/50">
      <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-3">
        <nav className="flex gap-1">
          {tabs.map((t) => (
            <button
              key={t.id}
              onClick={() => setTab(t.id)}
              className={clsx(
                'rounded-md px-2.5 py-1 text-xs font-medium',
                tab === t.id ? 'bg-white text-slate-900 shadow-xs ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-800',
              )}
            >
              {t.label}
            </button>
          ))}
        </nav>
        {canWrite && (
          <div className="flex gap-2">
            <Button size="sm" variant="secondary" icon={<GitBranch className="size-3.5" />} onClick={() => setModal('branch')}>
              New branch
            </Button>
            <Button size="sm" variant="secondary" icon={<GitPullRequest className="size-3.5" />} onClick={() => setModal('pull')}>
              Open pull request
            </Button>
          </div>
        )}
      </div>
      <div className="p-3">
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
          {tab === 'pulls' && <PullRequestList repository={repository} />}
          {tab === 'branches' && <BranchList repository={repository} />}
          {tab === 'commits' && <CommitList repository={repository} />}
          {tab === 'webhooks' && <DeliveryList repository={repository} />}
        </div>
      </div>

      {modal === 'branch' && <CreateBranchModal repository={repository} onClose={() => setModal(null)} />}
      {modal === 'pull' && <CreatePullRequestModal repository={repository} onClose={() => setModal(null)} />}
    </div>
  )
}

function useBranches(repository: Repository) {
  return useQuery({
    queryKey: ['branches', repository.id],
    queryFn: () => api<{ data: Branch[] }>(`/repositories/${repository.id}/branches`).then((r) => r.data),
  })
}

function PullRequestList({ repository }: { repository: Repository }) {
  const pulls = useQuery({
    queryKey: ['repository-pulls', repository.id],
    queryFn: () => api<Paginated<PullRequest>>(`/repositories/${repository.id}/pull-requests`),
  })

  if (pulls.isPending) return <LoadingBlock />
  if (pulls.isError) return <div className="p-4"><ErrorBanner error={pulls.error} /></div>
  if (pulls.data.data.length === 0) {
    return <EmptyState icon={<GitPullRequest className="size-5" />} title="No pull requests" description="Pull requests appear here after a sync or when GitHub sends a webhook." />
  }

  return <PullRequestTable pulls={pulls.data.data} />
}

export function PullRequestTable({ pulls, showRepository = false }: { pulls: PullRequest[]; showRepository?: boolean }) {
  return (
    <Table head={['Pull request', ...(showRepository ? ['Repository'] : []), 'Branches', 'State', 'CI', 'Opened']}>
      {pulls.map((pr) => (
        <tr key={pr.id}>
          <Td>
            <div className="flex items-center gap-2">
              <a href={pr.url ?? '#'} target="_blank" rel="noreferrer" className="font-medium text-slate-900 hover:text-brand-700">
                <span className="text-slate-400">#{pr.number}</span> {pr.title}
              </a>
              {pr.opened_via_platform && <Badge tone="info">Platform</Badge>}
            </div>
            <div className="text-xs text-slate-500">
              {pr.author_login ? `by ${pr.author_login}` : ''}
              {pr.opened_by ? ` · opened by ${pr.opened_by.name}` : ''}
            </div>
          </Td>
          {showRepository && (
            <Td>
              <div className="font-mono text-xs text-slate-700">{pr.repository?.full_name}</div>
              <div className="text-xs text-slate-500">{pr.project?.name}</div>
            </Td>
          )}
          <Td>
            <span className="font-mono text-xs whitespace-nowrap text-slate-600">
              {pr.head_branch} → {pr.base_branch}
            </span>
          </Td>
          <Td>
            <PullRequestStateBadge pr={pr} />
          </Td>
          <Td>
            <ChecksBadge status={pr.checks_status} />
          </Td>
          <Td className="whitespace-nowrap text-slate-500">{timeAgo(pr.opened_at ?? pr.created_at)}</Td>
        </tr>
      ))}
    </Table>
  )
}

function BranchList({ repository }: { repository: Repository }) {
  const branches = useBranches(repository)

  if (branches.isPending) return <LoadingBlock />
  if (branches.isError) return <div className="p-4"><ErrorBanner error={branches.error} /></div>

  return (
    <Table head={['Branch', 'Head', 'Rules']}>
      {branches.data.map((branch) => (
        <tr key={branch.name}>
          <Td>
            <span className="inline-flex items-center gap-1.5 font-mono text-xs text-slate-800">
              <GitBranch className="size-3.5 text-slate-400" /> {branch.name}
            </span>
            {branch.is_default && <Badge tone="info" className="ml-2">Default</Badge>}
          </Td>
          <Td className="font-mono text-xs text-slate-500">{branch.sha.slice(0, 7)}</Td>
          <Td>
            {branch.is_protected ? (
              <Badge tone="violet"><Lock className="size-3" /> Protected</Badge>
            ) : branch.is_writable ? (
              <Badge tone="success"><ShieldCheck className="size-3" /> Work branch</Badge>
            ) : (
              <span className="text-xs text-slate-400">Read-only for the platform</span>
            )}
          </Td>
        </tr>
      ))}
    </Table>
  )
}

function CommitList({ repository }: { repository: Repository }) {
  const branches = useBranches(repository)
  const [branch, setBranch] = useState(repository.default_branch)
  const commits = useQuery({
    queryKey: ['commits', repository.id, branch],
    queryFn: () => api<{ data: Commit[] }>(`/repositories/${repository.id}/commits`, { query: { branch, limit: 30 } }).then((r) => r.data),
  })

  return (
    <>
      <div className="border-b border-slate-100 px-4 py-2.5">
        <Select
          value={branch}
          onChange={(e) => setBranch(e.target.value)}
          options={(branches.data ?? [{ name: repository.default_branch }]).map((b) => ({ value: b.name, label: b.name }))}
          className="w-64 font-mono text-xs"
          aria-label="Branch"
        />
      </div>
      {commits.isPending ? (
        <LoadingBlock />
      ) : commits.isError ? (
        <div className="p-4"><ErrorBanner error={commits.error} /></div>
      ) : (
        <Table head={['Commit', 'Message', 'Author', 'When']}>
          {commits.data.map((commit) => (
            <tr key={commit.sha}>
              <Td>
                <a href={commit.url ?? '#'} target="_blank" rel="noreferrer" className="font-mono text-xs text-brand-700 hover:underline">
                  {commit.short_sha}
                </a>
              </Td>
              <Td className="max-w-md truncate text-slate-800">{commit.message.split('\n')[0]}</Td>
              <Td className="text-slate-600">{commit.author_login ?? commit.author_name ?? '—'}</Td>
              <Td className="whitespace-nowrap text-slate-500">{timeAgo(commit.committed_at)}</Td>
            </tr>
          ))}
        </Table>
      )}
    </>
  )
}

function DeliveryList({ repository }: { repository: Repository }) {
  const deliveries = useQuery({
    queryKey: ['deliveries', repository.id],
    queryFn: () => api<{ data: WebhookDelivery[] }>(`/repositories/${repository.id}/webhook-deliveries`).then((r) => r.data),
  })

  if (deliveries.isPending) return <LoadingBlock />
  if (deliveries.isError) return <div className="p-4"><ErrorBanner error={deliveries.error} /></div>
  if (deliveries.data.length === 0) {
    return (
      <EmptyState
        title="No deliveries yet"
        description={
          repository.webhook_status === 'failed'
            ? repository.webhook_error ?? 'The webhook could not be created.'
            : 'Events from GitHub (pushes, pull requests, issues, CI) are listed here as they arrive.'
        }
      />
    )
  }

  return (
    <Table head={['Event', 'Status', 'Received', 'Delivery']}>
      {deliveries.data.map((d) => (
        <tr key={d.id}>
          <Td className="font-mono text-xs text-slate-800">
            {d.event}
            {d.action && <span className="text-slate-400">.{d.action}</span>}
          </Td>
          <Td>
            <span title={d.error ?? undefined}>
              <StatusBadge status={d.status} />
            </span>
          </Td>
          <Td className="whitespace-nowrap text-slate-500">{timeAgo(d.received_at)}</Td>
          <Td className="font-mono text-xs text-slate-400">{d.delivery_id}</Td>
        </tr>
      ))}
    </Table>
  )
}

function CreateBranchModal({ repository, onClose }: { repository: Repository; onClose: () => void }) {
  const meta = useMeta()
  const branches = useBranches(repository)
  const queryClient = useQueryClient()
  const prefixes = meta.data?.git.work_branch_prefixes ?? ['fix', 'feature', 'refactor', 'security', 'maintenance']
  const [prefix, setPrefix] = useState(prefixes[0])
  const [slug, setSlug] = useState('')
  const [from, setFrom] = useState(repository.default_branch)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api(`/repositories/${repository.id}/branches`, { method: 'POST', body: { name: `${prefix}/${slug}`, from } })
      await queryClient.invalidateQueries({ queryKey: ['branches', repository.id] })
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not create the branch.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open onClose={onClose} title="New branch" description="The platform only writes to work branches; protected branches change through pull requests.">
      <form onSubmit={submit} className="space-y-4">
        {error && !error.field('name') && <ErrorBanner error={error} />}
        <Field label="Name" error={error?.field('name')} hint="Lowercase letters, digits, dots, dashes and underscores.">
          {(id) => (
            <div className="flex gap-2">
              <Select value={prefix} onChange={(e) => setPrefix(e.target.value)} options={prefixes.map((p) => ({ value: p, label: `${p}/` }))} className="w-40 font-mono" aria-label="Prefix" />
              <Input id={id} required value={slug} onChange={(e) => setSlug(e.target.value.toLowerCase().replace(/\s+/g, '-'))} placeholder="products-api-500" className="font-mono" />
            </div>
          )}
        </Field>
        <Field label="From" error={error?.field('from')}>
          {(id) => (
            <Select
              id={id}
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              options={(branches.data ?? [{ name: repository.default_branch }]).map((b) => ({ value: b.name, label: b.name }))}
              className="font-mono"
            />
          )}
        </Field>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={busy} icon={<Plus className="size-4" />}>
            Create branch
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function CreatePullRequestModal({ repository, onClose }: { repository: Repository; onClose: () => void }) {
  const branches = useBranches(repository)
  const queryClient = useQueryClient()
  const workBranches = (branches.data ?? []).filter((b) => b.is_writable)
  const [form, setForm] = useState({ head: '', base: repository.default_branch, title: '', body: '', draft: false })
  const head = form.head || workBranches[0]?.name || ''
  const [error, setError] = useState<ApiError | null>(null)
  const [created, setCreated] = useState<PullRequest | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const { data } = await api<{ data: PullRequest }>(`/repositories/${repository.id}/pull-requests`, { method: 'POST', body: { ...form, head } })
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['repository-pulls', repository.id] }),
        queryClient.invalidateQueries({ queryKey: ['pull-requests'] }),
      ])
      setCreated(data)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not open the pull request.'))
    } finally {
      setBusy(false)
    }
  }

  if (created) {
    return (
      <Modal open onClose={onClose} title={`Pull request #${created.number} opened`}>
        <div className="space-y-4 text-sm text-slate-600">
          <p>{created.title}</p>
          <div className="flex justify-end gap-2">
            {created.url && (
              <a href={created.url} target="_blank" rel="noreferrer">
                <Button variant="secondary" icon={<ExternalLink className="size-4" />}>
                  View on GitHub
                </Button>
              </a>
            )}
            <Button onClick={onClose}>Done</Button>
          </div>
        </div>
      </Modal>
    )
  }

  return (
    <Modal open onClose={onClose} title="Open pull request" size="lg">
      {branches.isPending ? (
        <LoadingBlock />
      ) : workBranches.length === 0 ? (
        <p className="text-sm text-slate-600">There is no work branch yet. Create a branch such as <code className="font-mono">fix/…</code> first.</p>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          {error && Object.keys(error.errors).length === 0 && <ErrorBanner error={error} />}
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="From (head)" error={error?.field('head')}>
              {(id) => <Select id={id} value={head} onChange={(e) => setForm({ ...form, head: e.target.value })} options={workBranches.map((b) => ({ value: b.name, label: b.name }))} className="font-mono" />}
            </Field>
            <Field label="Into (base)" error={error?.field('base')}>
              {(id) => (
                <Select
                  id={id}
                  value={form.base}
                  onChange={(e) => setForm({ ...form, base: e.target.value })}
                  options={(branches.data ?? []).filter((b) => b.name !== head).map((b) => ({ value: b.name, label: b.name }))}
                  className="font-mono"
                />
              )}
            </Field>
          </div>
          <Field label="Title" error={error?.field('title')}>
            {(id) => <Input id={id} required maxLength={256} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />}
          </Field>
          <Field label="Description" error={error?.field('body')}>
            {(id) => <Textarea id={id} rows={5} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} placeholder="What changed, why, and how it was tested." />}
          </Field>
          <Toggle checked={form.draft} onChange={(v) => setForm({ ...form, draft: v })} label="Draft" description="Reviewers are not requested until it is marked ready." />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" loading={busy} icon={<GitPullRequest className="size-4" />}>
              Open pull request
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
