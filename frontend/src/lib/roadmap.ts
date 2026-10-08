/**
 * Platform areas that are designed but delivered in later phases. The dashboard
 * shows them in navigation so the product shape is visible, and renders a phase
 * notice instead of fabricated data.
 */
export interface RoadmapItem {
  slug: string
  title: string
  phase: number
  summary: string
  capabilities: string[]
}

export const roadmap: RoadmapItem[] = [
  {
    slug: 'tasks',
    title: 'Tasks',
    phase: 3,
    summary: 'Every AI job is a task with runs, steps and tool calls you can follow in real time.',
    capabilities: ['Task lifecycle (planning → executing → reviewing → completed)', 'Runs, steps and tool-call history', 'Budgets: steps, tool calls, runtime, cost', 'Escalation to REQUIRES_HUMAN'],
  },
  {
    slug: 'agents',
    title: 'AI Agents',
    phase: 3,
    summary: 'Specialised agents coordinated by the orchestrator through the Tool Gateway.',
    capabilities: ['Code, Debug, Database, DevOps, Security and Reviewer agents', 'Provider-agnostic LLM layer', 'Policy engine: allow, deny, require approval'],
  },
  {
    slug: 'approvals',
    title: 'Approvals',
    phase: 3,
    summary: 'High-risk actions pause until a human approves or rejects them.',
    capabilities: ['Risk-based approval requests', 'Approve / reject with reason', 'Expiry and full audit trail'],
  },
  {
    slug: 'repositories',
    title: 'Repositories',
    phase: 2,
    summary: 'Connect GitHub to sync repositories, receive webhooks and open pull requests.',
    capabilities: ['GitHub App installation', 'Webhooks for issues, pushes and CI', 'Branches, commits and pull requests'],
  },
  {
    slug: 'pull-requests',
    title: 'Pull Requests',
    phase: 2,
    summary: 'Pull requests opened by agents, with tests, security scan and review status.',
    capabilities: ['AI-authored branches (fix/, feature/, security/ …)', 'CI status and retry on failure', 'Human review before merge'],
  },
  {
    slug: 'servers',
    title: 'Servers',
    phase: 6,
    summary: 'Live server and container diagnostics through allow-listed connectors.',
    capabilities: ['CPU, memory and disk', 'Docker status and logs', 'No arbitrary production shell'],
  },
  {
    slug: 'deployments',
    title: 'Deployments',
    phase: 6,
    summary: 'Staging-first deployments with production approval and automatic rollback.',
    capabilities: ['Deployment records (commit, image tag, environment)', 'Health checks and smoke tests', 'Regression detection and rollback'],
  },
  {
    slug: 'monitoring',
    title: 'Monitoring',
    phase: 7,
    summary: 'Metrics, logs and events from your environments feeding the agents.',
    capabilities: ['HTTP latency, error rate, request count', 'Container and database status', 'Event bus that creates agent tasks'],
  },
  {
    slug: 'incidents',
    title: 'Incidents',
    phase: 7,
    summary: 'Detected problems with timeline, AI analysis, root cause and postmortem.',
    capabilities: ['Severity INFO → CRITICAL', 'Timeline and actions', 'AI root-cause analysis and postmortem'],
  },
]

export function roadmapItem(slug: string): RoadmapItem | undefined {
  return roadmap.find((item) => item.slug === slug)
}
