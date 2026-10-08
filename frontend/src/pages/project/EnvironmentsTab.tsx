import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ExternalLink, GitBranch, KeyRound, Lock, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import {
  Badge,
  Button,
  Card,
  ConfirmDialog,
  EmptyState,
  ErrorBanner,
  Field,
  Input,
  LoadingBlock,
  Modal,
  Select,
  Table,
  Td,
  Toggle,
} from '../../components/ui'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { timeAgo, titleCase } from '../../lib/format'
import type { Environment, EnvironmentVariable, Project } from '../../lib/types'

const typeTone = { development: 'neutral', staging: 'info', production: 'violet' } as const

export function EnvironmentsTab({ project }: { project: Project }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<Environment | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Environment | null>(null)
  const [openVariables, setOpenVariables] = useState<string | null>(null)

  const environments = useQuery({
    queryKey: ['environments', project.id],
    queryFn: () => api<{ data: Environment[] }>(`/projects/${project.id}/environments`).then((r) => r.data),
  })

  const canManage = (env: Environment) => can(env.is_protected ? 'environments.manage_protected' : 'environments.manage')
  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['environments', project.id] }),
      queryClient.invalidateQueries({ queryKey: ['project', project.id] }),
    ])

  if (environments.isPending) return <LoadingBlock />
  if (environments.isError) return <ErrorBanner error={environments.error} />

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-500">
          Protected environments can only be changed by admins, and actions against them require approval.
        </p>
        {can('environments.manage') && (
          <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
            Add environment
          </Button>
        )}
      </div>

      {environments.data.length === 0 ? (
        <Card>
          <EmptyState title="No environments" description="Add development, staging and production environments for this project." />
        </Card>
      ) : (
        environments.data.map((env) => (
          <Card key={env.id}>
            <div className="flex flex-wrap items-start gap-4 px-5 py-4">
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <h3 className="font-mono text-sm font-semibold text-slate-900">{env.name}</h3>
                  <Badge tone={typeTone[env.type]}>{titleCase(env.type)}</Badge>
                  {env.is_protected && (
                    <Badge tone="violet">
                      <Lock className="size-3" /> Protected
                    </Badge>
                  )}
                  {env.requires_approval && (
                    <Badge tone="warning">
                      <ShieldCheck className="size-3" /> Approval required
                    </Badge>
                  )}
                </div>
                <dl className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
                  <div className="flex items-center gap-1.5">
                    <dt className="sr-only">URL</dt>
                    <ExternalLink className="size-3.5" />
                    <dd>
                      {env.url ? (
                        <a href={env.url} target="_blank" rel="noreferrer" className="hover:text-brand-700">
                          {env.url}
                        </a>
                      ) : (
                        'No URL'
                      )}
                    </dd>
                  </div>
                  <div className="flex items-center gap-1.5">
                    <dt className="sr-only">Branch</dt>
                    <GitBranch className="size-3.5" />
                    <dd className="font-mono">{env.branch ?? 'not set'}</dd>
                  </div>
                  <div>
                    <dt className="inline">Health check: </dt>
                    <dd className="inline font-mono">{env.health_check_url ?? '—'}</dd>
                  </div>
                </dl>
              </div>
              <div className="flex items-center gap-1">
                <Button
                  size="sm"
                  variant="secondary"
                  icon={<KeyRound className="size-3.5" />}
                  onClick={() => setOpenVariables(openVariables === env.id ? null : env.id)}
                >
                  Variables ({env.variables_count ?? 0})
                </Button>
                {canManage(env) && (
                  <>
                    <Button size="sm" variant="ghost" aria-label="Edit environment" onClick={() => setEditing(env)}>
                      <Pencil className="size-3.5" />
                    </Button>
                    <Button size="sm" variant="ghost" aria-label="Delete environment" onClick={() => setDeleting(env)}>
                      <Trash2 className="size-3.5" />
                    </Button>
                  </>
                )}
              </div>
            </div>
            {openVariables === env.id && <VariablesPanel environment={env} canWrite={can('secrets.manage') && canManage(env)} onChange={refresh} />}
          </Card>
        ))
      )}

      {editing && (
        <EnvironmentModal
          project={project}
          environment={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            await refresh()
            setEditing(null)
          }}
        />
      )}

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title={`Delete ${deleting?.name}?`}
        description={<p>This removes the environment and all of its variables. Infrastructure records attached to it are kept but unlinked.</p>}
        confirmLabel="Delete environment"
        onConfirm={async () => {
          await api(`/environments/${deleting!.id}`, { method: 'DELETE' })
          await refresh()
        }}
      />
    </div>
  )
}

function EnvironmentModal({ project, environment, onClose, onSaved }: {
  project: Project
  environment: Environment | null
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  const { can } = useAuth()
  const [form, setForm] = useState({
    name: environment?.name ?? '',
    type: environment?.type ?? 'staging',
    url: environment?.url ?? '',
    branch: environment?.branch ?? '',
    health_check_url: environment?.health_check_url ?? '',
    is_protected: environment?.is_protected ?? false,
    requires_approval: environment?.requires_approval ?? false,
  })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const canProtect = can('environments.manage_protected')

  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [key]: e.target.value })

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    const body = {
      url: form.url || null,
      branch: form.branch || null,
      health_check_url: form.health_check_url || null,
      ...(canProtect ? { is_protected: form.is_protected, requires_approval: form.requires_approval } : {}),
    }
    try {
      if (environment) {
        await api(`/environments/${environment.id}`, { method: 'PATCH', body })
      } else {
        await api(`/projects/${project.id}/environments`, { method: 'POST', body: { ...body, name: form.name, type: form.type } })
      }
      await onSaved()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not save the environment.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open onClose={onClose} title={environment ? `Edit ${environment.name}` : 'Add environment'}>
      <form onSubmit={submit} className="space-y-4">
        {error && Object.keys(error.errors).length === 0 && <ErrorBanner error={error} />}
        {!environment && (
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Name" error={error?.field('name')} hint="Lowercase, e.g. qa or prod-eu.">
              {(id) => <Input id={id} required value={form.name} onChange={set('name')} className="font-mono" />}
            </Field>
            <Field label="Type" error={error?.field('type')}>
              {(id) => (
                <Select
                  id={id}
                  value={form.type}
                  onChange={(e) => {
                    const type = e.target.value as Environment['type']
                    const protect = type === 'production'
                    setForm({ ...form, type, is_protected: protect, requires_approval: protect })
                  }}
                  options={canProtect ? ['development', 'staging', 'production'] : ['development', 'staging']}
                />
              )}
            </Field>
          </div>
        )}
        <Field label="URL" error={error?.field('url')}>
          {(id) => <Input id={id} type="url" value={form.url} onChange={set('url')} placeholder="https://staging.example.com" />}
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Branch" error={error?.field('branch')}>
            {(id) => <Input id={id} value={form.branch} onChange={set('branch')} className="font-mono" placeholder="main" />}
          </Field>
          <Field label="Health check URL" error={error?.field('health_check_url')}>
            {(id) => <Input id={id} type="url" value={form.health_check_url} onChange={set('health_check_url')} placeholder="https://…/up" />}
          </Field>
        </div>
        <div className="space-y-3 rounded-lg bg-slate-50 p-3">
          <Toggle
            checked={form.is_protected}
            disabled={!canProtect}
            onChange={(v) => setForm({ ...form, is_protected: v })}
            label="Protected"
            description="Only admins and owners can change this environment."
          />
          <Toggle
            checked={form.requires_approval}
            disabled={!canProtect}
            onChange={(v) => setForm({ ...form, requires_approval: v })}
            label="Require approval"
            description="Deployments and agent actions here wait for human approval."
          />
          {!canProtect && <p className="text-xs text-slate-500">Only admins and owners can change protection settings.</p>}
        </div>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={busy}>
            {environment ? 'Save changes' : 'Add environment'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function VariablesPanel({ environment, canWrite, onChange }: { environment: Environment; canWrite: boolean; onChange: () => Promise<unknown> }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState({ key: '', value: '', is_secret: true })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const key = ['variables', environment.id]

  const variables = useQuery({
    queryKey: key,
    queryFn: () => api<{ data: EnvironmentVariable[] }>(`/environments/${environment.id}/variables`).then((r) => r.data),
  })

  async function save(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api(`/environments/${environment.id}/variables`, { method: 'PUT', body: { variables: [form] } })
      setForm({ key: '', value: '', is_secret: true })
      await queryClient.invalidateQueries({ queryKey: key })
      await onChange()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not save the variable.'))
    } finally {
      setBusy(false)
    }
  }

  async function remove(variable: EnvironmentVariable) {
    if (!window.confirm(`Delete ${variable.key} from ${environment.name}?`)) return
    await api(`/environments/${environment.id}/variables/${variable.id}`, { method: 'DELETE' })
    await queryClient.invalidateQueries({ queryKey: key })
    await onChange()
  }

  return (
    <div className="border-t border-slate-100 bg-slate-50/50">
      {variables.isPending ? (
        <LoadingBlock />
      ) : variables.isError ? (
        <div className="p-4">
          <ErrorBanner error={variables.error} />
        </div>
      ) : variables.data.length === 0 ? (
        <p className="px-5 py-4 text-sm text-slate-500">No variables configured.</p>
      ) : (
        <Table head={['Key', 'Value', 'Type', 'Updated', '']}>
          {variables.data.map((variable) => (
            <tr key={variable.id}>
              <Td className="font-mono text-xs font-medium text-slate-800">{variable.key}</Td>
              <Td className="max-w-xs truncate font-mono text-xs text-slate-600">{variable.is_secret ? '••••••••' : variable.value}</Td>
              <Td>{variable.is_secret ? <Badge tone="violet">Secret</Badge> : <Badge>Plain</Badge>}</Td>
              <Td className="text-xs text-slate-500">{timeAgo(variable.updated_at)}</Td>
              <Td className="text-right">
                {canWrite && (
                  <button onClick={() => remove(variable)} className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-red-600" aria-label={`Delete ${variable.key}`}>
                    <Trash2 className="size-3.5" />
                  </button>
                )}
              </Td>
            </tr>
          ))}
        </Table>
      )}

      {canWrite ? (
        <form onSubmit={save} className="space-y-2 border-t border-slate-100 px-5 py-4">
          {error && <ErrorBanner error={error.field('variables.0.key') ? new Error(error.field('variables.0.key')) : error} />}
          <div className="flex flex-wrap items-center gap-2">
            <Input
              required
              value={form.key}
              onChange={(e) => setForm({ ...form, key: e.target.value.toUpperCase() })}
              placeholder="KEY_NAME"
              className="w-48 font-mono"
              aria-label="Variable key"
            />
            <Input
              required
              type={form.is_secret ? 'password' : 'text'}
              autoComplete="off"
              value={form.value}
              onChange={(e) => setForm({ ...form, value: e.target.value })}
              placeholder="value"
              className="min-w-48 flex-1 font-mono"
              aria-label="Variable value"
            />
            <Toggle checked={form.is_secret} onChange={(v) => setForm({ ...form, is_secret: v })} label="Secret" />
            <Button type="submit" size="sm" loading={busy}>
              Save
            </Button>
          </div>
          <p className="text-xs text-slate-500">Secret values are encrypted at rest, never returned by the API and never shown to AI models.</p>
        </form>
      ) : (
        <p className="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">You can view keys but not change variables in this environment.</p>
      )}
    </div>
  )
}
