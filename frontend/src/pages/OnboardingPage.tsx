import { Building2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Button, Card, ErrorBanner, Field, Input } from '../components/ui'
import { api, ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import type { Organization } from '../lib/types'

export function OnboardingPage() {
  const { organizations, refresh, selectOrganization, logout } = useAuth()
  const navigate = useNavigate()
  const [name, setName] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const { data } = await api<{ data: Organization }>('/organizations', { method: 'POST', body: { name }, tenant: false })
      selectOrganization(data.id)
      await refresh()
      navigate('/')
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Could not create the organization.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center px-4 py-12">
      <div className="w-full max-w-md">
        <div className="mb-6 flex size-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
          <Building2 className="size-5" />
        </div>
        <h1 className="text-xl font-semibold tracking-tight text-slate-900">Create an organization</h1>
        <p className="mt-1 text-sm text-slate-500">
          Organizations own projects, members and policies. Data is fully isolated between organizations.
        </p>

        <Card className="mt-6 p-5">
          <form onSubmit={submit} className="space-y-4">
            {error && !error.field('name') && <ErrorBanner error={error} />}
            <Field label="Organization name" error={error?.field('name')}>
              {(id) => <Input id={id} required autoFocus placeholder="Acme Engineering" value={name} onChange={(e) => setName(e.target.value)} />}
            </Field>
            <Button type="submit" loading={busy} className="w-full">
              Create organization
            </Button>
          </form>
        </Card>

        <div className="mt-4 flex justify-between text-sm">
          {organizations.length > 0 ? (
            <Link to="/" className="text-slate-500 hover:text-slate-700">
              ← Back to dashboard
            </Link>
          ) : (
            <span />
          )}
          <button
            onClick={async () => {
              await logout()
              navigate('/login')
            }}
            className="text-slate-500 hover:text-slate-700"
          >
            Sign out
          </button>
        </div>
      </div>
    </div>
  )
}
