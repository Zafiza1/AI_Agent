import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useState, type FormEvent } from 'react'
import { api, ApiError } from '../../lib/api'
import type { GitConnection, RemoteRepository, Repository } from '../../lib/types'
import { Button, ErrorBanner, Field, LoadingBlock, Modal, Select, Toggle } from '../ui'

/**
 * Link a registered repository to one of the organization's git connections.
 */
export function ConnectRepositoryModal({ repository, onClose, onConnected }: {
  repository: Repository
  onClose: () => void
  onConnected: () => Promise<unknown>
}) {
  const connections = useQuery({
    queryKey: ['git-connections'],
    queryFn: () => api<{ data: GitConnection[] }>('/git-connections').then((r) => r.data),
  })
  const usable = (connections.data ?? []).filter((c) => c.status === 'active' && c.provider === repository.provider)

  const [connectionId, setConnectionId] = useState(repository.git_connection_id ?? '')
  const selected = usable.find((c) => c.id === (connectionId || usable[0]?.id))
  const [createWebhook, setCreateWebhook] = useState(true)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  // Show whether the selected connection can actually see this repository.
  const remote = useQuery({
    queryKey: ['remote-repositories', selected?.id],
    queryFn: () => api<{ data: RemoteRepository[] }>(`/git-connections/${selected!.id}/remote-repositories`).then((r) => r.data),
    enabled: !!selected,
    staleTime: 60_000,
  })
  const visible = remote.data?.some((r) => r.full_name.toLowerCase() === repository.full_name?.toLowerCase())

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!selected) return
    setBusy(true)
    setError(null)
    try {
      await api(`/repositories/${repository.id}/connect`, {
        method: 'POST',
        body: { git_connection_id: selected.id, create_webhook: createWebhook },
      })
      await onConnected()
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not connect the repository.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open onClose={onClose} title="Connect repository" description={repository.full_name ?? repository.url}>
      {connections.isPending ? (
        <LoadingBlock />
      ) : usable.length === 0 ? (
        <div className="space-y-4 text-sm text-slate-600">
          <p>
            There is no active {repository.provider} connection in this organization yet. An admin can add one under{' '}
            <Link to="/settings#integrations" className="font-medium text-brand-700 hover:underline">
              Settings → Integrations
            </Link>
            .
          </p>
          <div className="flex justify-end">
            <Button variant="secondary" onClick={onClose}>
              Close
            </Button>
          </div>
        </div>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          {error && <ErrorBanner error={error.field('git_connection_id') ? new Error(error.field('git_connection_id')) : error} />}
          <Field
            label="Connection"
            hint={
              remote.isPending
                ? 'Checking access…'
                : remote.isError
                  ? 'Could not list repositories for this connection.'
                  : visible
                    ? 'This connection can access the repository.'
                    : 'This repository is not in the list this connection can access.'
            }
          >
            {(id) => (
              <Select
                id={id}
                value={selected?.id ?? ''}
                onChange={(e) => setConnectionId(e.target.value)}
                options={usable.map((c) => ({ value: c.id, label: `${c.name}${c.auth_type === 'github_app' ? ' (GitHub App)' : ''}` }))}
              />
            )}
          </Field>
          {selected?.auth_type === 'personal_access_token' && (
            <Toggle
              checked={createWebhook}
              onChange={setCreateWebhook}
              label="Create a repository webhook"
              description="Delivers pushes, pull requests, issues and CI results. Needs webhook admin permission and a publicly reachable platform URL."
            />
          )}
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" loading={busy}>
              Connect
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
