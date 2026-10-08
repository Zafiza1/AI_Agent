import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Trash2, UserPlus, X } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { Badge, Button, Card, CardHeader, ConfirmDialog, ErrorBanner, Field, Input, LoadingBlock, Modal, PageHeader, Select, Table, Td } from '../components/ui'
import { api, ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import { initials, timeAgo, titleCase } from '../lib/format'
import { useMeta } from '../lib/queries'
import type { Member, Permission, Role } from '../lib/types'

export function SettingsPage() {
  const { organization } = useAuth()

  return (
    <>
      <PageHeader title="Settings" description={`Organization settings for ${organization?.name}`} />
      <div className="space-y-6">
        <OrganizationCard />
        <MembersCard />
        <RoleMatrix />
        <DangerZone />
      </div>
    </>
  )
}

function OrganizationCard() {
  const { organization, can, refresh } = useAuth()
  const [name, setName] = useState(organization?.name ?? '')
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    setSaved(false)
    try {
      await api('/organization', { method: 'PATCH', body: { name } })
      await refresh()
      setSaved(true)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not save.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader title="Organization" description="All projects, members and audit logs belong to this organization." />
      <form onSubmit={submit} className="grid gap-4 px-5 py-4 sm:grid-cols-2">
        <Field label="Name" error={error?.field('name')}>
          {(id) => <Input id={id} value={name} onChange={(e) => setName(e.target.value)} disabled={!can('organization.update')} required />}
        </Field>
        <Field label="Slug" hint="Used in URLs and integrations. Cannot be changed.">
          {(id) => <Input id={id} value={organization?.slug ?? ''} disabled className="font-mono" />}
        </Field>
        {can('organization.update') && (
          <div className="flex items-center gap-3 sm:col-span-2">
            <Button type="submit" loading={busy}>
              Save
            </Button>
            {saved && <span className="text-sm text-emerald-600">Saved</span>}
            {error && !error.field('name') && <ErrorBanner error={error} />}
          </div>
        )}
      </form>
    </Card>
  )
}

const roleTone: Record<Role, 'violet' | 'info' | 'success' | 'neutral'> = {
  owner: 'violet',
  admin: 'info',
  maintainer: 'success',
  viewer: 'neutral',
}

function MembersCard() {
  const { can, user, organization } = useAuth()
  const queryClient = useQueryClient()
  const [inviting, setInviting] = useState(false)
  const [removing, setRemoving] = useState<Member | null>(null)
  const [rowError, setRowError] = useState<unknown>(null)
  const canManage = can('members.manage')
  const assignable: Role[] = organization?.role === 'owner' ? ['owner', 'admin', 'maintainer', 'viewer'] : ['admin', 'maintainer', 'viewer']

  const members = useQuery({
    queryKey: ['members'],
    queryFn: () => api<{ data: Member[] }>('/organization/members').then((r) => r.data),
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['members'] })

  async function changeRole(member: Member, role: Role) {
    setRowError(null)
    try {
      await api(`/organization/members/${member.id}`, { method: 'PATCH', body: { role } })
      await refresh()
    } catch (e) {
      setRowError(e)
    }
  }

  return (
    <Card>
      <CardHeader
        title="Members"
        description="People with access to this organization and their role."
        actions={
          canManage && (
            <Button size="sm" icon={<UserPlus className="size-3.5" />} onClick={() => setInviting(true)}>
              Add member
            </Button>
          )
        }
      />
      {rowError ? (
        <div className="px-5 pt-4">
          <ErrorBanner error={rowError} />
        </div>
      ) : null}
      {members.isPending ? (
        <LoadingBlock />
      ) : members.isError ? (
        <div className="p-5">
          <ErrorBanner error={members.error} />
        </div>
      ) : (
        <Table head={['Member', 'Role', 'Joined', '']}>
          {members.data.map((member) => {
            const isSelf = member.user.id === user?.id
            const editable = canManage && !isSelf && (member.role !== 'owner' || organization?.role === 'owner')
            return (
              <tr key={member.id}>
                <Td>
                  <div className="flex items-center gap-3">
                    <span className="flex size-8 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">
                      {initials(member.user.name)}
                    </span>
                    <div>
                      <div className="text-sm font-medium text-slate-900">
                        {member.user.name} {isSelf && <span className="text-xs font-normal text-slate-400">(you)</span>}
                      </div>
                      <div className="text-xs text-slate-500">{member.user.email}</div>
                    </div>
                  </div>
                </Td>
                <Td>
                  {editable ? (
                    <Select value={member.role} onChange={(e) => changeRole(member, e.target.value as Role)} options={assignable} className="w-36" aria-label={`Role of ${member.user.name}`} />
                  ) : (
                    <Badge tone={roleTone[member.role]}>{titleCase(member.role)}</Badge>
                  )}
                </Td>
                <Td className="text-xs text-slate-500">{timeAgo(member.joined_at)}</Td>
                <Td className="text-right">
                  {(editable || isSelf) && (
                    <button
                      onClick={() => setRemoving(member)}
                      className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-red-600"
                      aria-label={isSelf ? 'Leave organization' : `Remove ${member.user.name}`}
                      title={isSelf ? 'Leave organization' : 'Remove member'}
                    >
                      <Trash2 className="size-3.5" />
                    </button>
                  )}
                </Td>
              </tr>
            )
          })}
        </Table>
      )}

      {inviting && <AddMemberModal roles={assignable} onClose={() => setInviting(false)} onSaved={async () => { await refresh(); setInviting(false) }} />}

      <RemoveMemberDialog member={removing} onClose={() => setRemoving(null)} onRemoved={refresh} />
    </Card>
  )
}

function RemoveMemberDialog({ member, onClose, onRemoved }: { member: Member | null; onClose: () => void; onRemoved: () => Promise<unknown> }) {
  const { user, refresh, organization, organizations, selectOrganization } = useAuth()
  const navigate = useNavigate()
  const isSelf = member?.user.id === user?.id

  return (
    <ConfirmDialog
      open={member !== null}
      onClose={onClose}
      title={isSelf ? 'Leave organization?' : `Remove ${member?.user.name}?`}
      description={<p>{isSelf ? 'You will lose access to all projects in this organization.' : 'They will immediately lose access to this organization.'}</p>}
      confirmLabel={isSelf ? 'Leave' : 'Remove'}
      onConfirm={async () => {
        await api(`/organization/members/${member!.id}`, { method: 'DELETE' })
        if (isSelf) {
          const next = organizations.find((o) => o.id !== organization?.id)
          if (next) selectOrganization(next.id)
          await refresh()
          navigate(next ? '/' : '/onboarding')
        } else {
          await onRemoved()
        }
      }}
    />
  )
}

function AddMemberModal({ roles, onClose, onSaved }: { roles: Role[]; onClose: () => void; onSaved: () => Promise<void> }) {
  const [form, setForm] = useState<{ email: string; role: Role }>({ email: '', role: 'viewer' })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await api('/organization/members', { method: 'POST', body: form })
      await onSaved()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not add the member.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal open onClose={onClose} title="Add member" description="The person must already have an account. Email invitations come in a later phase.">
      <form onSubmit={submit} className="space-y-4">
        {error && Object.keys(error.errors).length === 0 && <ErrorBanner error={error} />}
        <Field label="Email" error={error?.field('email')}>
          {(id) => <Input id={id} type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />}
        </Field>
        <Field label="Role" error={error?.field('role')}>
          {(id) => <Select id={id} value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as Role })} options={roles} />}
        </Field>
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={busy}>
            Add member
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function RoleMatrix() {
  const meta = useMeta()
  if (!meta.data) return null

  const groups = meta.data.permissions.reduce<Record<string, Permission[]>>((acc, p) => {
    const group = p.split('.')[0]
    ;(acc[group] ??= []).push(p)
    return acc
  }, {})

  return (
    <Card>
      <CardHeader title="Roles and permissions" description="What each role can do. Roles are fixed in this phase." />
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs font-medium text-slate-500 uppercase">
              <th className="px-5 py-2.5 font-medium">Permission</th>
              {meta.data.roles.map((r) => (
                <th key={r.value} className="px-5 py-2.5 text-center font-medium">
                  {titleCase(r.value)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {Object.entries(groups).map(([group, permissions]) =>
              permissions.map((permission, i) => (
                <tr key={permission}>
                  <td className="px-5 py-2">
                    {i === 0 && <span className="mr-2 text-xs font-semibold text-slate-400 uppercase">{group}</span>}
                    <span className="font-mono text-xs text-slate-700">{permission}</span>
                  </td>
                  {meta.data.roles.map((r) => (
                    <td key={r.value} className="px-5 py-2 text-center">
                      {r.permissions.includes(permission) ? (
                        <Check className="mx-auto size-4 text-emerald-600" aria-label="Allowed" />
                      ) : (
                        <X className="mx-auto size-4 text-slate-300" aria-label="Not allowed" />
                      )}
                    </td>
                  ))}
                </tr>
              )),
            )}
          </tbody>
        </table>
      </div>
    </Card>
  )
}

function DangerZone() {
  const { organization, can, refresh, organizations, selectOrganization } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [confirm, setConfirm] = useState('')

  if (!can('organization.delete') || !organization) return null

  return (
    <Card className="border-red-200">
      <CardHeader title="Danger zone" />
      <div className="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
        <div>
          <p className="text-sm font-medium text-slate-900">Delete organization</p>
          <p className="text-xs text-slate-500">Permanently deletes every project, environment, secret and member. The audit trail is retained.</p>
        </div>
        <Button variant="danger" onClick={() => setOpen(true)}>
          Delete organization
        </Button>
      </div>
      <ConfirmDialog
        open={open}
        onClose={() => {
          setOpen(false)
          setConfirm('')
        }}
        title={`Delete ${organization.name}?`}
        confirmLabel="Delete permanently"
        description={
          <div className="space-y-3">
            <p>
              This cannot be undone. Type <span className="font-mono font-semibold text-slate-900">{organization.slug}</span> to confirm.
            </p>
            <Input value={confirm} onChange={(e) => setConfirm(e.target.value)} className="font-mono" aria-label="Confirm organization slug" />
          </div>
        }
        onConfirm={async () => {
          await api('/organization', { method: 'DELETE', body: { confirm } })
          const next = organizations.find((o) => o.id !== organization.id)
          if (next) selectOrganization(next.id)
          await refresh()
          navigate(next ? '/' : '/onboarding')
        }}
      />
    </Card>
  )
}
