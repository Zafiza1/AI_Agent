import { ShieldAlert } from 'lucide-react'
import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Button, ErrorBanner, Field, Input } from '../components/ui'
import { ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'

function AuthLayout({ title, subtitle, children, footer }: {
  title: string
  subtitle: string
  children: ReactNode
  footer: ReactNode
}) {
  return (
    <div className="grid min-h-full lg:grid-cols-2">
      <div className="hidden flex-col justify-between bg-sidebar p-10 lg:flex">
        <div className="flex items-center gap-2.5">
          <span className="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-violet-500 text-white">
            <ShieldAlert className="size-4" />
          </span>
          <span className="font-semibold text-white">Maintainer</span>
        </div>
        <div className="max-w-md">
          <h2 className="text-3xl font-semibold tracking-tight text-white">Your AI software maintenance department.</h2>
          <p className="mt-4 text-sm leading-relaxed text-slate-400">
            Detect, diagnose, fix, test and ship — with sandboxed execution, risk-based approvals and a complete audit
            trail for every action an agent takes.
          </p>
          <ul className="mt-8 space-y-3 text-sm text-slate-300">
            {['Human approval for high-risk actions', 'Strict tenant isolation and RBAC', 'Secrets never reach the model'].map((item) => (
              <li key={item} className="flex items-center gap-2.5">
                <span className="size-1.5 rounded-full bg-brand-500" /> {item}
              </li>
            ))}
          </ul>
        </div>
        <p className="text-xs text-slate-600">AI Software Maintenance Platform</p>
      </div>

      <div className="flex items-center justify-center px-4 py-12 sm:px-8">
        <div className="w-full max-w-sm">
          <h1 className="text-xl font-semibold tracking-tight text-slate-900">{title}</h1>
          <p className="mt-1 text-sm text-slate-500">{subtitle}</p>
          <div className="mt-8">{children}</div>
          <p className="mt-6 text-center text-sm text-slate-500">{footer}</p>
        </div>
      </div>
    </div>
  )
}

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await login(email, password)
      navigate('/')
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Login failed.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthLayout
      title="Sign in"
      subtitle="Welcome back. Sign in to your workspace."
      footer={
        <>
          No account?{' '}
          <Link to="/register" className="font-medium text-brand-600 hover:text-brand-700">
            Create one
          </Link>
        </>
      }
    >
      <form onSubmit={submit} className="space-y-4">
        {error && !error.field('email') && <ErrorBanner error={error} />}
        <Field label="Email" error={error?.field('email')}>
          {(id) => <Input id={id} type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} />}
        </Field>
        <Field label="Password" error={error?.field('password')}>
          {(id) => (
            <Input id={id} type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
          )}
        </Field>
        <Button type="submit" loading={busy} className="w-full">
          Sign in
        </Button>
      </form>
    </AuthLayout>
  )
}

export function RegisterPage() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' })
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)

  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [key]: e.target.value })

  async function submit(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await register(form)
      navigate('/onboarding')
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'Registration failed.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthLayout
      title="Create your account"
      subtitle="Start maintaining your software with AI agents."
      footer={
        <>
          Already registered?{' '}
          <Link to="/login" className="font-medium text-brand-600 hover:text-brand-700">
            Sign in
          </Link>
        </>
      }
    >
      <form onSubmit={submit} className="space-y-4">
        {error && Object.keys(error.errors).length === 0 && <ErrorBanner error={error} />}
        <Field label="Full name" error={error?.field('name')}>
          {(id) => <Input id={id} autoComplete="name" required value={form.name} onChange={set('name')} />}
        </Field>
        <Field label="Work email" error={error?.field('email')}>
          {(id) => <Input id={id} type="email" autoComplete="email" required value={form.email} onChange={set('email')} />}
        </Field>
        <Field label="Password" error={error?.field('password')} hint="At least 10 characters with letters and numbers.">
          {(id) => <Input id={id} type="password" autoComplete="new-password" required value={form.password} onChange={set('password')} />}
        </Field>
        <Field label="Confirm password">
          {(id) => (
            <Input id={id} type="password" autoComplete="new-password" required value={form.password_confirmation} onChange={set('password_confirmation')} />
          )}
        </Field>
        <Button type="submit" loading={busy} className="w-full">
          Create account
        </Button>
      </form>
    </AuthLayout>
  )
}
