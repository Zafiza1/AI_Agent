import { useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Plug, RefreshCw, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Badge, Button, Card, CardHeader, ConfirmDialog, EmptyState, ErrorBanner, Field, Input, LoadingBlock, Modal, StatusBadge, Table, Td } from '../../components/ui'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { timeAgo } from '../../lib/format'
import { useMeta } from '../../lib/queries'
import type { GitConnection } from '../../lib/types'

export function IntegrationsCard() {
  const { can } = useAuth()
  const meta = useMeta()
  const queryClient = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [removing, setRemoving] = useState<GitConnection | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<unknown>(null)
  const canManage = can('integrations.manage')
  const appEnabled = meta.data?.integrations.github_app.enabled ?? false

  const connections = useQuery({
    queryKey: ['git-connections'],
    queryFn: () => api<{ data: GitConnection[] }>('/git-connections').then((r) => r.data),
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['git-connections'] })

  async function installApp() {
    setBusy('install')
    setError(null)
    try {
      const { data } = await api<{ data: { url: string } }>('/git-connections/github/install', { method: 'POST' })
      window.location.assign(data.url)
    } catch (e) {
      setError(e)
      setBusy(null)
    }
  }

  async function verify(connection: GitConnection) {
    setBusy(connection.id)
    setError(null)
    try {
      await api(`/git-connections/${connection.id}/verify`, { method: 'POST' })
      await refresh()
    } catch (e) {
      setError(e)
    } finally {
      setBusy(null)
    }
  }

  return (
    <Card>
      <div id="integrations" className="scroll-mt-6" />
      <CardHeader
        title="Integrations"
        description="Git provider accounts the platform uses to read repositories, open branches and pull requests, and receive webhooks."
        actions={
          canManage && (
            <>
              <Button size="sm" variant="secondary" icon={<KeyRound className="size-3.5" />} onClick={() => setAdding(true)}>
                Add access token
              </Button>
              {appEnabled && (
                <Button size="sm" icon={<Plug className="size-3.5" />} loading={busy === 'install'} onClick={installApp}>
                  Install GitHub App
                </Button>
              )}
            </>
          )
        }
      />
      {error ? (
        <div className="px-5 pt-4">
          <ErrorBanner error={error} />
        </div>
      ) : null}
      {connections.isPending ? (
        <LoadingBlock />
      ) : connections.isError ? (
        <div className="p-5">
          <ErrorBanner error={connections.error} />
        </div>
      ) : connections.data.length === 0 ? (
        <EmptyState
          icon={<Plug className="size-5" />}
          title="No git provider connected"
          description={
            appEnabled
              ? 'Install the GitHub App (recommended) or add a fine-grained personal access token.'
              : 'Add a fine-grained personal access token. The GitHub App option appears once the platform operator configures it.'
          }
        />
      ) : (
        <Table head={['Connection', 'Type', 'Status', 'Repositories', 'Verified', '']}>
          {connections.data.map((connection) => (
            <tr key={connection.id}>
              <Td>
                <div className="font-medium text-slate-900">{connection.name}</div>
                <div className="text-xs text-slate-500">
                  {connection.account_login && `@${connection.account_login}`}
                  {connection.created_by && ` · added by ${connection.created_by.name}`}
                </div>
              </Td>
              <Td>
                <Badge tone={connection.auth_type === 'github_app' ? 'violet' : 'neutral'}>
                  {connection.auth_type === 'github_app' ? 'GitHub App' : 'Access token'}
                </Badge>
              </Td>
              <Td>
                <span title={connection.last_error ?? undefined}>
                  <StatusBadge status={connection.status} />
                </span>
                {connection.last_error && <div className="mt-1 max-w-xs text-xs text-red-600">{connection.last_error}</div>}
              </Td>
              <Td className="text-slate-600">{connection.repositories_count ?? 0}</Td>
              <Td className="whitespace-nowrap text-slate-500">{timeAgo(connection.last_verified_at)}</Td>
              <Td className="text-right whitespace-nowrap">
                {canManage && (
                  <>
                    <button
                      onClick={() => verify(connection)}
                      disabled={busy === connection.id}
                      className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                      title="Verify"
                      aria-label="Verify connection"
                    >
                      <RefreshCw className={busy === connection.id ? 'size-3.5 animate-spin' : 'size-3.5'} />
                    </button>
                    <button onClick={() => setRemoving(connection)} className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-red-600" aria-label="Remove connection">
                      <Trash2 className="size-3.5" />
                    </button>
                  </>
                )}
              </Td>
            </tr>
          ))}
        </Table>
      )}

      {adding && <AddTokenModal onClose={() => setAdding(false)} onSaved={refresh} />}

      <ConfirmDialog
        open={removing !== null}
        onClose={() => setRemoving(null)}
        title="Remove connection?"
        description={
          <p>
            {removing?.repositories_count
              ? `${removing.repositories_count} repositories will be disconnected and their platform webhooks removed. `
              : ''}
            {removing?.auth_type === 'github_app' ? 'The GitHub App stays installed on GitHub; uninstall it there if you no longer need it.' : 'The token is deleted from the platform; revoke it on GitHub as well.'}
          </p>
        }
        confirmLabel="Remove"
        onConfirm={async () => {
          await api(`/git-connections/${removing!.id}`, { method: 'DELETE' })
          await Promise.all([refresh(), queryClient.invalidateQueries({ queryKey: ['repositories'] })])
        }}
      />
    </Card>
  )
}

function AddTokenModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => Promise<unknown> }) {
  const [form, setForm] = useState({ name: '', token: '' })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api('/git-connections', { method: 'POST', body: { provider: 'github', ...form, name: form.name || null } })
      await onSaved()
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not add the token.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open onClose={onClose} title="Add GitHub access token" description="The token is verified with GitHub, stored encrypted and never shown again.">
      <form onSubmit={submit} className="space-y-4">
        {error && Object.keys(error.errors).length === 0 && <ErrorBanner error={error} />}
        <Field label="Name" error={error?.field('name')} hint="Optional. Defaults to the GitHub account name.">
          {(id) => <Input id={id} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Acme bot" />}
        </Field>
        <Field
          label="Token"
          error={error?.field('token')}
          hint="Fine-grained token with Contents, Pull requests and Metadata access (plus Webhooks to create repository webhooks)."
        >
          {(id) => (
            <Input id={id} type="password" required autoComplete="off" value={form.token} onChange={(e) => setForm({ ...form, token: e.target.value.trim() })} placeholder="github_pat_…" className="font-mono" />
          )}
        </Field>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={busy}>
            Verify and save
          </Button>
        </div>
      </form>
    </Modal>
  )
}
