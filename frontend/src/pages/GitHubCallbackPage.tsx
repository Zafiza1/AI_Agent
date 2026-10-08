import { useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Clock } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Button, Card, ErrorBanner, Spinner } from '../components/ui'
import { api } from '../lib/api'
import type { GitConnection } from '../lib/types'

type Result = { kind: 'linked'; connection: GitConnection } | { kind: 'requested'; message: string } | { kind: 'error'; error: unknown }

/**
 * Setup URL of the GitHub App. GitHub redirects here after an installation with
 * installation_id, setup_action, code and the state the platform issued.
 */
export function GitHubCallbackPage() {
  const [params] = useSearchParams()
  const queryClient = useQueryClient()
  const [result, setResult] = useState<Result | null>(null)
  const sent = useRef(false)

  useEffect(() => {
    // The state is single-use; StrictMode would otherwise submit it twice.
    if (sent.current) return
    sent.current = true

    const body = {
      installation_id: params.get('installation_id') ? Number(params.get('installation_id')) : null,
      setup_action: params.get('setup_action'),
      code: params.get('code'),
      state: params.get('state') ?? '',
    }

    api<{ data?: GitConnection; message?: string }>('/git-connections/github/callback', { method: 'POST', body })
      .then(async (response) => {
        await queryClient.invalidateQueries({ queryKey: ['git-connections'] })
        setResult(response.data ? { kind: 'linked', connection: response.data } : { kind: 'requested', message: response.message ?? '' })
      })
      .catch((error) => setResult({ kind: 'error', error }))
  }, [params, queryClient])

  return (
    <div className="mx-auto max-w-lg py-16">
      <Card className="p-6">
        {result === null ? (
          <div className="flex items-center gap-3 text-sm text-slate-600">
            <Spinner /> Linking the GitHub installation…
          </div>
        ) : result.kind === 'linked' ? (
          <div className="space-y-3">
            <CheckCircle2 className="size-8 text-emerald-500" />
            <h1 className="text-lg font-semibold text-slate-900">GitHub connected</h1>
            <p className="text-sm text-slate-600">
              {result.connection.name} is ready. Connect repositories from a project's Repositories tab.
            </p>
            <Link to="/settings#integrations">
              <Button>Back to settings</Button>
            </Link>
          </div>
        ) : result.kind === 'requested' ? (
          <div className="space-y-3">
            <Clock className="size-8 text-amber-500" />
            <h1 className="text-lg font-semibold text-slate-900">Waiting for approval</h1>
            <p className="text-sm text-slate-600">{result.message}</p>
            <Link to="/settings#integrations">
              <Button variant="secondary">Back to settings</Button>
            </Link>
          </div>
        ) : (
          <div className="space-y-4">
            <h1 className="text-lg font-semibold text-slate-900">Could not connect GitHub</h1>
            <ErrorBanner error={result.error} />
            <Link to="/settings#integrations">
              <Button variant="secondary">Back to settings</Button>
            </Link>
          </div>
        )}
      </Card>
    </div>
  )
}
