import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Database, Pencil, Plus, Server as ServerIcon, Trash2, Workflow } from 'lucide-react'
import { useState, type FormEvent, type ReactNode } from 'react'
import { Button, Card, CardHeader, ConfirmDialog, EmptyState, ErrorBanner, Field, Input, LoadingBlock, Modal, Select, StatusBadge, Table, Td } from '../../components/ui'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useMeta } from '../../lib/queries'
import type { DatabaseInstance, Environment, Meta, Project, Server, Service } from '../../lib/types'

type Kind = 'servers' | 'databases' | 'services'
type Row = Server | DatabaseInstance | Service

interface Lookups {
  environments: Environment[]
  servers: Server[]
  meta?: Meta
}

interface FieldDef {
  name: string
  label: string
  kind?: 'text' | 'number' | 'select'
  options?: (lookups: Lookups) => (string | { value: string; label: string })[]
  required?: boolean
  mono?: boolean
  placeholder?: string
}

interface SectionDef {
  kind: Kind
  title: string
  description: string
  icon: ReactNode
  fields: FieldDef[]
  columns: { label: string; render: (row: never, lookups: Lookups) => ReactNode }[]
}

const environmentField: FieldDef = {
  name: 'environment_id',
  label: 'Environment',
  kind: 'select',
  options: ({ environments }) => environments.map((e) => ({ value: e.id, label: e.name })),
}
const serverField: FieldDef = {
  name: 'server_id',
  label: 'Server',
  kind: 'select',
  options: ({ servers }) => servers.map((s) => ({ value: s.id, label: s.name })),
}
const statusField: FieldDef = { name: 'status', label: 'Status', kind: 'select', options: ({ meta }) => meta?.enums.resource_status ?? [] }

const envName = (id: string | null, { environments }: Lookups) => environments.find((e) => e.id === id)?.name ?? '—'
const serverName = (id: string | null, { servers }: Lookups) => servers.find((s) => s.id === id)?.name ?? '—'
const mono = (value: ReactNode) => <span className="font-mono text-xs text-slate-600">{value || '—'}</span>

const sections: SectionDef[] = [
  {
    kind: 'servers',
    title: 'Servers',
    description: 'Hosts that run this project. Credentials are configured as environment secrets, never here.',
    icon: <ServerIcon className="size-4" />,
    fields: [
      { name: 'name', label: 'Name', required: true, mono: true, placeholder: 'web-01' },
      environmentField,
      { name: 'hostname', label: 'Hostname', mono: true, placeholder: 'web-01.example.com' },
      { name: 'ip_address', label: 'IP address', mono: true },
      { name: 'provider', label: 'Provider', placeholder: 'AWS, Hostinger, on-prem…' },
      { name: 'os', label: 'Operating system', placeholder: 'Ubuntu 24.04' },
      { name: 'connection_type', label: 'Connection', kind: 'select', options: ({ meta }) => meta?.enums.server_connection_type ?? [] },
      statusField,
    ],
    columns: [
      { label: 'Name', render: (s: Server) => <span className="font-medium text-slate-900">{s.name}</span> },
      { label: 'Host', render: (s: Server) => mono(s.hostname ?? s.ip_address) },
      { label: 'Environment', render: (s: Server, l) => envName(s.environment_id, l) },
      { label: 'Provider', render: (s: Server) => s.provider ?? '—' },
      { label: 'Status', render: (s: Server) => <StatusBadge status={s.status} /> },
    ],
  },
  {
    kind: 'databases',
    title: 'Databases',
    description: 'Database instances. Production databases are read-only for agents by default.',
    icon: <Database className="size-4" />,
    fields: [
      { name: 'name', label: 'Name', required: true, mono: true, placeholder: 'main-db' },
      { name: 'engine', label: 'Engine', kind: 'select', required: true, options: ({ meta }) => meta?.enums.database_engine ?? [] },
      environmentField,
      serverField,
      { name: 'version', label: 'Version', placeholder: '16' },
      { name: 'host', label: 'Host', mono: true },
      { name: 'port', label: 'Port', kind: 'number' },
      { name: 'database_name', label: 'Database name', mono: true },
      statusField,
    ],
    columns: [
      { label: 'Name', render: (d: DatabaseInstance) => <span className="font-medium text-slate-900">{d.name}</span> },
      { label: 'Engine', render: (d: DatabaseInstance) => `${d.engine}${d.version ? ` ${d.version}` : ''}` },
      { label: 'Endpoint', render: (d: DatabaseInstance) => mono(d.host ? `${d.host}${d.port ? `:${d.port}` : ''}` : null) },
      { label: 'Environment', render: (d: DatabaseInstance, l) => envName(d.environment_id, l) },
      { label: 'Status', render: (d: DatabaseInstance) => <StatusBadge status={d.status} /> },
    ],
  },
  {
    kind: 'services',
    title: 'Services',
    description: 'Processes and containers: web, API, workers, schedulers.',
    icon: <Workflow className="size-4" />,
    fields: [
      { name: 'name', label: 'Name', required: true, mono: true, placeholder: 'api' },
      { name: 'type', label: 'Type', kind: 'select', required: true, options: ({ meta }) => meta?.enums.service_type ?? [] },
      { name: 'runtime', label: 'Runtime', kind: 'select', options: ({ meta }) => meta?.enums.service_runtime ?? [] },
      environmentField,
      serverField,
      { name: 'container_name', label: 'Container name', mono: true },
      { name: 'port', label: 'Port', kind: 'number' },
      { name: 'health_check_url', label: 'Health check URL', mono: true },
      statusField,
    ],
    columns: [
      { label: 'Name', render: (s: Service) => <span className="font-medium text-slate-900">{s.name}</span> },
      { label: 'Type', render: (s: Service) => `${s.type} · ${s.runtime}` },
      { label: 'Container', render: (s: Service) => mono(s.container_name) },
      { label: 'Server', render: (s: Service, l) => serverName(s.server_id, l) },
      { label: 'Status', render: (s: Service) => <StatusBadge status={s.status} /> },
    ],
  },
]

export function InfrastructureTab({ project }: { project: Project }) {
  const meta = useMeta()
  const environments = useQuery({
    queryKey: ['environments', project.id],
    queryFn: () => api<{ data: Environment[] }>(`/projects/${project.id}/environments`).then((r) => r.data),
  })
  const servers = useQuery({
    queryKey: ['infra', project.id, 'servers'],
    queryFn: () => api<{ data: Server[] }>(`/projects/${project.id}/servers`).then((r) => r.data),
  })

  const lookups: Lookups = { environments: environments.data ?? [], servers: servers.data ?? [], meta: meta.data }

  return (
    <div className="space-y-6">
      {sections.map((section) => (
        <InfraSection key={section.kind} project={project} section={section} lookups={lookups} />
      ))}
    </div>
  )
}

function InfraSection({ project, section, lookups }: { project: Project; section: SectionDef; lookups: Lookups }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<Row | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Row | null>(null)
  const canManage = can('infrastructure.manage')

  const rows = useQuery({
    queryKey: ['infra', project.id, section.kind],
    queryFn: () => api<{ data: Row[] }>(`/projects/${project.id}/${section.kind}`).then((r) => r.data),
  })

  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['infra', project.id] }),
      queryClient.invalidateQueries({ queryKey: ['project', project.id] }),
    ])

  return (
    <Card>
      <CardHeader
        title={
          <span className="flex items-center gap-2">
            <span className="text-slate-400">{section.icon}</span> {section.title}
          </span>
        }
        description={section.description}
        actions={
          canManage && (
            <Button size="sm" variant="secondary" icon={<Plus className="size-3.5" />} onClick={() => setEditing('new')}>
              Add
            </Button>
          )
        }
      />
      {rows.isPending ? (
        <LoadingBlock />
      ) : rows.isError ? (
        <div className="p-5">
          <ErrorBanner error={rows.error} />
        </div>
      ) : rows.data.length === 0 ? (
        <EmptyState title={`No ${section.title.toLowerCase()} registered`} />
      ) : (
        <Table head={[...section.columns.map((c) => c.label), '']}>
          {rows.data.map((row) => (
            <tr key={row.id}>
              {section.columns.map((column) => (
                <Td key={column.label}>{column.render(row as never, lookups)}</Td>
              ))}
              <Td className="text-right whitespace-nowrap">
                {canManage && (
                  <>
                    <button onClick={() => setEditing(row)} className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label={`Edit ${row.name}`}>
                      <Pencil className="size-3.5" />
                    </button>
                    <button onClick={() => setDeleting(row)} className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-red-600" aria-label={`Delete ${row.name}`}>
                      <Trash2 className="size-3.5" />
                    </button>
                  </>
                )}
              </Td>
            </tr>
          ))}
        </Table>
      )}

      {editing && (
        <InfraModal
          project={project}
          section={section}
          lookups={lookups}
          row={editing === 'new' ? null : editing}
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
        title={`Remove ${deleting?.name}?`}
        description={<p>This removes the record from the registry. The actual infrastructure is not touched.</p>}
        confirmLabel="Remove"
        onConfirm={async () => {
          await api(`/${section.kind}/${deleting!.id}`, { method: 'DELETE' })
          await refresh()
        }}
      />
    </Card>
  )
}

function InfraModal({ project, section, lookups, row, onClose, onSaved }: {
  project: Project
  section: SectionDef
  lookups: Lookups
  row: Row | null
  onClose: () => void
  onSaved: () => Promise<void>
}) {
  const [values, setValues] = useState<Record<string, string>>(() =>
    Object.fromEntries(section.fields.map((f) => [f.name, String((row as Record<string, unknown> | null)?.[f.name] ?? '')])),
  )
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)

    const body: Record<string, string | number | null> = {}
    for (const field of section.fields) {
      const raw = values[field.name].trim()
      // Enum selects without a value are omitted so the server default applies.
      if (raw === '' && field.kind === 'select' && !field.name.endsWith('_id')) continue
      body[field.name] = raw === '' ? null : field.kind === 'number' ? Number(raw) : raw
    }

    try {
      if (row) await api(`/${section.kind}/${row.id}`, { method: 'PATCH', body })
      else await api(`/projects/${project.id}/${section.kind}`, { method: 'POST', body })
      await onSaved()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not save.'))
    } finally {
      setBusy(false)
    }
  }

  const singular = section.title.slice(0, -1).toLowerCase()

  return (
    <Modal open onClose={onClose} title={row ? `Edit ${row.name}` : `Add ${singular}`} size="lg">
      <form onSubmit={submit} className="space-y-4">
        {error && Object.keys(error.errors).length === 0 && <ErrorBanner error={error} />}
        <div className="grid gap-4 sm:grid-cols-2">
          {section.fields.map((field) => (
            <Field key={field.name} label={field.label} error={error?.field(field.name)}>
              {(id) =>
                field.kind === 'select' ? (
                  <Select
                    id={id}
                    required={field.required}
                    value={values[field.name]}
                    onChange={(e) => setValues({ ...values, [field.name]: e.target.value })}
                    options={field.options?.(lookups) ?? []}
                    placeholder={field.required ? 'Select…' : 'None'}
                  />
                ) : (
                  <Input
                    id={id}
                    type={field.kind === 'number' ? 'number' : 'text'}
                    required={field.required}
                    value={values[field.name]}
                    placeholder={field.placeholder}
                    className={field.mono ? 'font-mono' : undefined}
                    onChange={(e) => setValues({ ...values, [field.name]: e.target.value })}
                  />
                )
              }
            </Field>
          ))}
        </div>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={busy}>
            {row ? 'Save changes' : `Add ${singular}`}
          </Button>
        </div>
      </form>
    </Modal>
  )
}
