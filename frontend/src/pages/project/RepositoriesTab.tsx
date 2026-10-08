import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronDown, ChevronRight, FolderGit2, GitBranch, Link2, Lock, Plus, RefreshCw, Star, Trash2, Unlink } from 'lucide-react'
import { Fragment, useState, type FormEvent } from 'react'
import { ConnectRepositoryModal } from '../../components/git/ConnectRepositoryModal'
import { RepositoryPanel } from '../../components/git/RepositoryPanel'
import { WebhookBadge } from '../../components/git/badges'
import { Badge, Button, Card, CardHeader, ConfirmDialog, EmptyState, ErrorBanner, Field, Input, LoadingBlock, Modal, StatusBadge, Table, Td, Toggle } from '../../components/ui'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { timeAgo, titleCase } from '../../lib/format'
import type { Project, Repository } from '../../lib/types'

export function RepositoriesTab({ project }: { project: Project }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [deleting, setDeleting] = useState<Repository | null>(null)
  const [connecting, setConnecting] = useState<Repository | null>(null)
  const [disconnecting, setDisconnecting] = useState<Repository | null>(null)
  const [expanded, setExpanded] = useState<string | null>(null)
  const [syncing, setSyncing] = useState<string | null>(null)
  const [actionError, setActionError] = useState<unknown>(null)
  const canManage = can('projects.update')

  const repositories = useQuery({
    queryKey: ['repositories', project.id],
    queryFn: () => api<{ data: Repository[] }>(`/projects/${project.id}/repositories`).then((r) => r.data),
  })

  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['repositories'] }),
      queryClient.invalidateQueries({ queryKey: ['project', project.id] }),
    ])

  async function sync(repo: Repository) {
    setSyncing(repo.id)
    setActionError(null)
    try {
      await api(`/repositories/${repo.id}/sync`, { method: 'POST' })
      await Promise.all([refresh(), queryClient.invalidateQueries({ queryKey: ['repository-pulls', repo.id] }), queryClient.invalidateQueries({ queryKey: ['branches', repo.id] })])
    } catch (e) {
      setActionError(e)
      await refresh()
    } finally {
      setSyncing(null)
    }
  }

  return (
    <div className="space-y-4">
      <ErrorBanner error={actionError} />
      <Card>
        <CardHeader
          title="Repositories"
          description="Source code agents read, branch from and open pull requests against."
          actions={
            canManage && (
              <Button size="sm" variant="secondary" icon={<Plus className="size-3.5" />} onClick={() => setAdding(true)}>
                Add repository
              </Button>
            )
          }
        />
        {repositories.isPending ? (
          <LoadingBlock />
        ) : repositories.isError ? (
          <div className="p-5">
            <ErrorBanner error={repositories.error} />
          </div>
        ) : repositories.data.length === 0 ? (
          <EmptyState icon={<FolderGit2 className="size-5" />} title="No repositories" description="Add the repository URL so agents know where the code lives." />
        ) : (
          <Table head={['Repository', 'Default branch', 'Connection', 'Webhook', 'Open PRs', '']}>
            {repositories.data.map((repo) => {
              const connected = repo.connection_status === 'connected'
              const open = expanded === repo.id && connected
              return (
                <Fragment key={repo.id}>
                  <tr className={open ? 'bg-slate-50/50' : undefined}>
                    <Td>
                      <div className="flex items-center gap-2">
                        <button
                          onClick={() => setExpanded(open ? null : repo.id)}
                          disabled={!connected}
                          className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:invisible"
                          aria-label={open ? 'Collapse' : 'Expand'}
                        >
                          {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                        </button>
                        <a href={repo.url} target="_blank" rel="noreferrer" className="font-mono text-xs font-medium text-slate-800 hover:text-brand-700">
                          {repo.full_name ?? repo.url}
                        </a>
                        {repo.is_private && <Lock className="size-3 text-slate-400" aria-label="Private" />}
                        {repo.is_primary && <Badge tone="info">Primary</Badge>}
                      </div>
                      <div className="mt-0.5 pl-7 text-xs text-slate-500">
                        {titleCase(repo.provider)}
                        {repo.git_connection && ` · via ${repo.git_connection.name}`}
                        {repo.last_synced_at && ` · synced ${timeAgo(repo.last_synced_at)}`}
                      </div>
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
                    <Td>{connected ? <WebhookBadge repository={repo} /> : <span className="text-slate-400">—</span>}</Td>
                    <Td className="text-slate-600">{connected ? (repo.open_pull_requests_count ?? 0) : '—'}</Td>
                    <Td className="text-right whitespace-nowrap">
                      {canManage && repo.provider === 'github' && repo.connection_status !== 'connected' && (
                        <Button size="sm" variant="secondary" icon={<Link2 className="size-3.5" />} onClick={() => setConnecting(repo)} className="mr-1">
                          {repo.connection_status === 'error' ? 'Reconnect' : 'Connect'}
                        </Button>
                      )}
                      {canManage && repo.git_connection_id && (
                        <button
                          onClick={() => sync(repo)}
                          disabled={syncing === repo.id}
                          className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                          title="Sync from provider"
                          aria-label="Sync from provider"
                        >
                          <RefreshCw className={syncing === repo.id ? 'size-3.5 animate-spin' : 'size-3.5'} />
                        </button>
                      )}
                      {canManage && repo.git_connection_id && (
                        <button onClick={() => setDisconnecting(repo)} className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-amber-600" title="Disconnect" aria-label="Disconnect">
                          <Unlink className="size-3.5" />
                        </button>
                      )}
                      {canManage && !repo.is_primary && (
                        <button
                          onClick={async () => {
                            await api(`/repositories/${repo.id}`, { method: 'PATCH', body: { is_primary: true } })
                            await refresh()
                          }}
                          className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-amber-600"
                          title="Make primary"
                          aria-label="Make primary"
                        >
                          <Star className="size-3.5" />
                        </button>
                      )}
                      {canManage && (
                        <button onClick={() => setDeleting(repo)} className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-red-600" aria-label="Remove repository">
                          <Trash2 className="size-3.5" />
                        </button>
                      )}
                    </Td>
                  </tr>
                  {open && (
                    <tr>
                      <td colSpan={6} className="p-0">
                        <RepositoryPanel repository={repo} />
                      </td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
          </Table>
        )}
      </Card>

      {adding && (
        <AddRepositoryModal
          project={project}
          onClose={() => setAdding(false)}
          onSaved={async () => {
            await refresh()
            setAdding(false)
          }}
        />
      )}

      {connecting && <ConnectRepositoryModal repository={connecting} onClose={() => setConnecting(null)} onConnected={refresh} />}

      <ConfirmDialog
        open={disconnecting !== null}
        onClose={() => setDisconnecting(null)}
        title="Disconnect repository?"
        description={<p>The platform stops reading this repository and removes the webhook it created. The repository on GitHub is not changed.</p>}
        confirmLabel="Disconnect"
        onConfirm={async () => {
          await api(`/repositories/${disconnecting!.id}/disconnect`, { method: 'POST' })
          await refresh()
        }}
      />

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title="Remove repository?"
        description={<p>The repository record is removed from this project. Nothing changes in the repository itself.</p>}
        confirmLabel="Remove"
        onConfirm={async () => {
          await api(`/repositories/${deleting!.id}`, { method: 'DELETE' })
          await refresh()
        }}
      />
    </div>
  )
}

function AddRepositoryModal({ project, onClose, onSaved }: { project: Project; onClose: () => void; onSaved: () => Promise<void> }) {
  const [form, setForm] = useState({ url: '', default_branch: 'main', is_primary: false })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api(`/projects/${project.id}/repositories`, { method: 'POST', body: form })
      await onSaved()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not add the repository.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open onClose={onClose} title="Add repository">
      <form onSubmit={submit} className="space-y-4">
        {error && Object.keys(error.errors).length === 0 && <ErrorBanner error={error} />}
        <Field label="Repository URL" error={error?.field('url')}>
          {(id) => <Input id={id} type="url" required value={form.url} onChange={(e) => setForm({ ...form, url: e.target.value })} placeholder="https://github.com/acme/worker" />}
        </Field>
        <Field label="Default branch" error={error?.field('default_branch')} hint="Replaced by the provider's default branch once connected.">
          {(id) => <Input id={id} value={form.default_branch} onChange={(e) => setForm({ ...form, default_branch: e.target.value })} className="font-mono" />}
        </Field>
        <Toggle checked={form.is_primary} onChange={(v) => setForm({ ...form, is_primary: v })} label="Primary repository" />
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={busy}>
            Add repository
          </Button>
        </div>
      </form>
    </Modal>
  )
}
