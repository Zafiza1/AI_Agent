import { CircleCheck, CircleDashed, CircleX, GitMerge, GitPullRequest, GitPullRequestClosed, GitPullRequestDraft } from 'lucide-react'
import { Badge } from '../ui'
import type { PullRequest, Repository } from '../../lib/types'

export function PullRequestStateBadge({ pr }: { pr: Pick<PullRequest, 'state' | 'is_draft'> }) {
  if (pr.state === 'merged') return <Badge tone="violet"><GitMerge className="size-3" /> Merged</Badge>
  if (pr.state === 'closed') return <Badge tone="neutral"><GitPullRequestClosed className="size-3" /> Closed</Badge>
  if (pr.is_draft) return <Badge tone="neutral"><GitPullRequestDraft className="size-3" /> Draft</Badge>
  return <Badge tone="success"><GitPullRequest className="size-3" /> Open</Badge>
}

export function ChecksBadge({ status }: { status: PullRequest['checks_status'] }) {
  if (status === 'success') return <Badge tone="success"><CircleCheck className="size-3" /> Checks passed</Badge>
  if (status === 'failure') return <Badge tone="danger"><CircleX className="size-3" /> Checks failed</Badge>
  if (status === 'pending') return <Badge tone="warning"><CircleDashed className="size-3" /> Checks running</Badge>
  return <span className="text-xs text-slate-400">—</span>
}

const webhookLabels: Record<Repository['webhook_status'], string> = {
  not_configured: 'No webhook',
  managed: 'GitHub App',
  active: 'Webhook active',
  failed: 'Webhook failed',
}

export function WebhookBadge({ repository }: { repository: Pick<Repository, 'webhook_status' | 'webhook_error'> }) {
  const tone = repository.webhook_status === 'failed' ? 'danger' : repository.webhook_status === 'not_configured' ? 'neutral' : 'success'
  return (
    <span title={repository.webhook_error ?? undefined}>
      <Badge tone={tone} dot>
        {webhookLabels[repository.webhook_status]}
      </Badge>
    </span>
  )
}
