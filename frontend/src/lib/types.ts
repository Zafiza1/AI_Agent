export type Role = 'owner' | 'admin' | 'maintainer' | 'viewer'

export type Permission =
  | 'organization.view'
  | 'organization.update'
  | 'organization.delete'
  | 'members.view'
  | 'members.manage'
  | 'projects.view'
  | 'projects.create'
  | 'projects.update'
  | 'projects.delete'
  | 'environments.manage'
  | 'environments.manage_protected'
  | 'secrets.manage'
  | 'infrastructure.manage'
  | 'integrations.manage'
  | 'repositories.write'
  | 'audit.view'

export type ProjectStatus = 'onboarding' | 'active' | 'paused' | 'archived'
export type EnvironmentType = 'development' | 'staging' | 'production'
export type ResourceStatus = 'unknown' | 'healthy' | 'warning' | 'critical' | 'offline'

export interface User {
  id: number
  name: string
  email: string
  created_at: string
}

export interface Organization {
  id: string
  name: string
  slug: string
  settings: Record<string, unknown>
  role: Role | null
  permissions: Permission[]
  members_count?: number
  projects_count?: number
  created_at: string
}

export interface Member {
  id: string
  role: Role
  user: User
  joined_at: string
}

export type WebhookStatus = 'not_configured' | 'managed' | 'active' | 'failed'

export interface Repository {
  id: string
  project_id: string
  provider: string
  url: string
  full_name: string | null
  default_branch: string
  is_primary: boolean
  is_private: boolean | null
  connection_status: 'not_connected' | 'connected' | 'error'
  connection_error: string | null
  git_connection_id: string | null
  git_connection?: Pick<GitConnection, 'id' | 'name' | 'auth_type' | 'status'> | null
  project?: { id: string; name: string }
  webhook_status: WebhookStatus
  webhook_error: string | null
  last_commit_sha: string | null
  last_pushed_at: string | null
  last_synced_at: string | null
  open_pull_requests_count?: number
  created_at: string
  updated_at: string
}

export interface GitConnection {
  id: string
  provider: string
  auth_type: 'github_app' | 'personal_access_token'
  name: string
  account_login: string | null
  account_type: string | null
  installation_id: number | null
  scopes: string[] | Record<string, string>
  status: 'active' | 'suspended' | 'error' | 'revoked'
  last_error: string | null
  last_verified_at: string | null
  repositories_count?: number
  created_by?: User | null
  created_at: string
}

export interface RemoteRepository {
  id: string
  full_name: string
  url: string
  default_branch: string
  private: boolean
  archived: boolean
  can_push: boolean
}

export interface Branch {
  name: string
  sha: string
  is_default: boolean
  is_protected: boolean
  is_writable: boolean
}

export interface Commit {
  sha: string
  short_sha: string
  message: string
  author_name: string | null
  author_login: string | null
  committed_at: string | null
  url: string | null
}

export interface PullRequest {
  id: string
  project_id: string
  repository_id: string
  provider: string
  number: number
  title: string
  state: 'open' | 'closed' | 'merged'
  is_draft: boolean
  head_branch: string
  head_sha: string | null
  base_branch: string
  url: string | null
  author_login: string | null
  checks_status: 'pending' | 'success' | 'failure' | 'neutral' | null
  opened_via_platform: boolean
  opened_by?: User | null
  repository?: { id: string; full_name: string | null }
  project?: { id: string; name: string }
  opened_at: string | null
  merged_at: string | null
  closed_at: string | null
  synced_at: string | null
  created_at: string
}

export interface WebhookDelivery {
  id: string
  delivery_id: string
  event: string
  action: string | null
  status: 'received' | 'processed' | 'ignored' | 'failed'
  error: string | null
  received_at: string
  processed_at: string | null
}

export interface Environment {
  id: string
  project_id: string
  name: string
  type: EnvironmentType
  url: string | null
  branch: string | null
  health_check_url: string | null
  is_protected: boolean
  requires_approval: boolean
  deployment_config: Record<string, unknown>
  variables_count?: number
  created_at: string
  updated_at: string
}

export interface EnvironmentVariable {
  id: string
  environment_id: string
  key: string
  is_secret: boolean
  value: string | null
  updated_at: string
}

export interface Project {
  id: string
  organization_id: string
  name: string
  slug: string
  description: string | null
  repository_url: string | null
  repository_provider: string | null
  default_branch: string | null
  framework: string | null
  language: string | null
  database_type: string | null
  deployment_type: string | null
  status: ProjectStatus
  ai_context: { notes?: string } & Record<string, unknown>
  primary_repository?: Repository | null
  environments?: Environment[]
  counts: Partial<Record<'environments' | 'repositories' | 'servers' | 'databases' | 'services', number>>
  created_by?: User | null
  created_at: string
  updated_at: string
}

export interface Server {
  id: string
  project_id: string
  environment_id: string | null
  name: string
  hostname: string | null
  ip_address: string | null
  provider: string | null
  os: string | null
  connection_type: string
  status: ResourceStatus
  created_at: string
}

export interface DatabaseInstance {
  id: string
  project_id: string
  environment_id: string | null
  server_id: string | null
  name: string
  engine: string
  version: string | null
  host: string | null
  port: number | null
  database_name: string | null
  status: ResourceStatus
  created_at: string
}

export interface Service {
  id: string
  project_id: string
  environment_id: string | null
  server_id: string | null
  name: string
  type: string
  runtime: string
  container_name: string | null
  port: number | null
  health_check_url: string | null
  status: ResourceStatus
  created_at: string
}

export interface AuditLog {
  id: string
  organization_id: string | null
  project_id: string | null
  actor_type: 'user' | 'agent' | 'system'
  user?: User | null
  agent_id: string | null
  action: string
  tool: string | null
  target_type: string | null
  target_id: string | null
  risk_level: 'low' | 'medium' | 'high' | 'critical' | null
  approval_id: string | null
  result: 'success' | 'failure' | 'denied'
  metadata: Record<string, unknown>
  ip_address: string | null
  created_at: string
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface Meta {
  roles: { value: Role; permissions: Permission[] }[]
  permissions: Permission[]
  enums: Record<
    | 'project_status'
    | 'repository_provider'
    | 'environment_type'
    | 'database_engine'
    | 'service_type'
    | 'service_runtime'
    | 'server_connection_type'
    | 'resource_status'
    | 'git_connection_status'
    | 'pull_request_state',
    string[]
  >
  integrations: { github_app: { enabled: boolean; slug: string | null } }
  git: { work_branch_prefixes: string[] }
}

export interface Overview {
  projects: { total: number; by_status: Partial<Record<ProjectStatus, number>> }
  environments: { total: number; protected: number }
  infrastructure: {
    servers: number
    databases: number
    services: number
    health: Record<ResourceStatus, number>
  }
  members: number
  recent_activity: AuditLog[]
}

export interface SystemHealth {
  status: 'healthy' | 'degraded'
  checks: Record<'database' | 'redis' | 'agent', { status: 'up' | 'down'; latency_ms: number }>
}
