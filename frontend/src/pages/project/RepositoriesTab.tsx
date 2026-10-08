import { useQuery, useQueryClient } from '@tanstack/react-query'
import { FolderGit2, GitBranch, Plus, Star, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Badge, Button, Card, CardHeader, ConfirmDialog, EmptyState, ErrorBanner, Field, Input, LoadingBlock, Modal, StatusBadge, Table, Td, Toggle } from '../../components/ui'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { titleCase } from '../../lib/format'
import type { Project, Repository } from '../../lib/types'

export function RepositoriesTab({ project }: { project: Project }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [deleting, setDeleting] = useState<Repository | null>(null)
  const canManage = can('projects.update')

  const repositories = useQuery({
    queryKey: ['repositories', project.id],
    queryFn: () => api<{ data: Repository[] }>(`/projects/${project.id}/repositories`).then((r) => r.data),
  })

  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['repositories', project.id] }),
      queryClient.invalidateQueries({ queryKey: ['project', project.id] }),
    ])

  return (
    <div className="space-y-4">
      <div className="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
        Repositories are registered here. Connecting a provider (GitHub App, webhooks, branches and pull requests) arrives in Phase 2.
      </div>

      <Card>
        <CardHeader
          title="Repositories"
          description="Source code agents will read and propose changes to."
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
          <Table head={['Repository', 'Provider', 'Default branch', 'Connection', '']}>
            {repositories.data.map((repo) => (
              <tr key={repo.id}>
                <Td>
                  <div className="flex items-center gap-2">
                    <a href={repo.url} target="_blank" rel="noreferrer" className="font-mono text-xs font-medium text-slate-800 hover:text-brand-700">
                      {repo.full_name ?? repo.url}
                    </a>
                    {repo.is_primary && <Badge tone="info">Primary</Badge>}
                  </div>
                </Td>
                <Td>{titleCase(repo.provider)}</Td>
                <Td>
                  <span className="inline-flex items-center gap-1.5 font-mono text-xs text-slate-600">
                    <GitBranch className="size-3.5 text-slate-400" /> {repo.default_branch}
                  </span>
                </Td>
                <Td>
                  <StatusBadge status={repo.connection_status} />
                </Td>
                <Td className="text-right whitespace-nowrap">
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
            ))}
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
        <Field label="Default branch" error={error?.field('default_branch')}>
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
