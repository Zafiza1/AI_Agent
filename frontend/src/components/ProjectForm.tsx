import { useState, type FormEvent } from 'react'
import { ApiError } from '../lib/api'
import { useMeta } from '../lib/queries'
import type { Project } from '../lib/types'
import { Button, ErrorBanner, Field, Input, Select, Textarea, Toggle } from './ui'

export interface ProjectInput {
  name: string
  description: string
  repository_url: string
  default_branch: string
  framework: string
  language: string
  database_type: string
  deployment_type: string
  status?: string
  ai_context?: { notes: string }
  create_default_environments?: boolean
}

function initial(project?: Project): ProjectInput {
  return {
    name: project?.name ?? '',
    description: project?.description ?? '',
    repository_url: project?.repository_url ?? '',
    default_branch: project?.default_branch ?? 'main',
    framework: project?.framework ?? '',
    language: project?.language ?? '',
    database_type: project?.database_type ?? '',
    deployment_type: project?.deployment_type ?? '',
    status: project?.status ?? 'onboarding',
    ai_context: { notes: project?.ai_context?.notes ?? '' },
    create_default_environments: true,
  }
}

/** Empty strings become null so optional fields can be cleared. */
function toPayload(input: ProjectInput, creating: boolean) {
  const nullable = (v: string) => (v.trim() === '' ? null : v.trim())

  return {
    name: input.name.trim(),
    description: nullable(input.description),
    repository_url: nullable(input.repository_url),
    default_branch: nullable(input.default_branch),
    framework: nullable(input.framework),
    language: nullable(input.language),
    database_type: nullable(input.database_type),
    deployment_type: nullable(input.deployment_type),
    status: input.status,
    ai_context: { notes: input.ai_context?.notes ?? '' },
    ...(creating ? { create_default_environments: input.create_default_environments } : {}),
  }
}

export function ProjectForm({ project, onSubmit, onCancel, submitLabel }: {
  project?: Project
  onSubmit: (payload: ReturnType<typeof toPayload>) => Promise<unknown>
  onCancel?: () => void
  submitLabel: string
}) {
  const creating = !project
  const meta = useMeta()
  const [form, setForm] = useState<ProjectInput>(() => initial(project))
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const set = (key: keyof ProjectInput) => (e: { target: { value: string } }) => setForm({ ...form, [key]: e.target.value })

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await onSubmit(toPayload(form, creating))
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not save the project.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="space-y-5">
      {error && Object.keys(error.errors).length === 0 && <ErrorBanner error={error} />}

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Project name" error={error?.field('name')}>
            {(id) => <Input id={id} required value={form.name} onChange={set('name')} placeholder="SIG Website" />}
          </Field>
        </div>
        <div className="sm:col-span-2">
          <Field label="Description" error={error?.field('description')}>
            {(id) => <Textarea id={id} rows={2} value={form.description} onChange={set('description')} />}
          </Field>
        </div>
        <Field label="Repository URL" error={error?.field('repository_url')} hint="GitHub, GitLab or Bitbucket over HTTPS.">
          {(id) => <Input id={id} type="url" value={form.repository_url} onChange={set('repository_url')} placeholder="https://github.com/acme/app" />}
        </Field>
        <Field label="Default branch" error={error?.field('default_branch')}>
          {(id) => <Input id={id} value={form.default_branch} onChange={set('default_branch')} className="font-mono" />}
        </Field>
        <Field label="Framework" error={error?.field('framework')}>
          {(id) => <Input id={id} value={form.framework} onChange={set('framework')} placeholder="Laravel + React" />}
        </Field>
        <Field label="Language" error={error?.field('language')}>
          {(id) => <Input id={id} value={form.language} onChange={set('language')} placeholder="PHP / TypeScript" />}
        </Field>
        <Field label="Database" error={error?.field('database_type')}>
          {(id) => (
            <Select id={id} value={form.database_type} onChange={set('database_type')} placeholder="Not set" options={meta.data?.enums.database_engine ?? []} />
          )}
        </Field>
        <Field label="Deployment" error={error?.field('deployment_type')}>
          {(id) => <Input id={id} value={form.deployment_type} onChange={set('deployment_type')} placeholder="docker, kubernetes, vps…" />}
        </Field>
        {!creating && (
          <Field label="Status" error={error?.field('status')}>
            {(id) => <Select id={id} value={form.status} onChange={set('status')} options={meta.data?.enums.project_status ?? []} />}
          </Field>
        )}
        <div className="sm:col-span-2">
          <Field
            label="AI context notes"
            error={error?.field('ai_context.notes')}
            hint="Facts and rules every agent must know, e.g. “Never modify the production database directly.”"
          >
            {(id) => (
              <Textarea
                id={id}
                rows={3}
                value={form.ai_context?.notes ?? ''}
                onChange={(e) => setForm({ ...form, ai_context: { notes: e.target.value } })}
              />
            )}
          </Field>
        </div>
        {creating && (
          <div className="sm:col-span-2">
            <Toggle
              checked={form.create_default_environments ?? true}
              onChange={(value) => setForm({ ...form, create_default_environments: value })}
              label="Create default environments"
              description="development, staging and production (production is protected and approval-gated)."
            />
          </div>
        )}
      </div>

      <div className="flex justify-end gap-2">
        {onCancel && (
          <Button variant="secondary" onClick={onCancel}>
            Cancel
          </Button>
        )}
        <Button type="submit" loading={busy}>
          {submitLabel}
        </Button>
      </div>
    </form>
  )
}
