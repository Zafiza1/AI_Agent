import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronRight, ScrollText } from 'lucide-react'
import { Fragment, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Badge, Button, Card, EmptyState, ErrorBanner, Input, LoadingBlock, PageHeader, Select, StatusBadge, Table, Td } from '../components/ui'
import { api } from '../lib/api'
import { formatDateTime, titleCase } from '../lib/format'
import type { AuditLog, Paginated } from '../lib/types'

export function AuditLogsPage() {
  const [params, setParams] = useSearchParams()
  const [expanded, setExpanded] = useState<string | null>(null)

  const query = {
    action: params.get('action') ?? '',
    result: params.get('result') ?? '',
    actor_type: params.get('actor_type') ?? '',
    page: Number(params.get('page') ?? 1),
    per_page: 50,
  }

  const logs = useQuery({
    queryKey: ['audit-logs', query],
    queryFn: () => api<Paginated<AuditLog>>('/audit-logs', { query }),
    placeholderData: (previous) => previous,
  })

  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next)
  }

  return (
    <>
      <PageHeader
        title="Audit logs"
        description="Append-only record of every change made by people, agents and the system. Sensitive values are redacted."
      />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
          <Input
            defaultValue={query.action}
            onKeyDown={(e) => e.key === 'Enter' && setParam('action', e.currentTarget.value.trim())}
            onBlur={(e) => setParam('action', e.currentTarget.value.trim())}
            placeholder="Action prefix, e.g. project. or environment."
            className="max-w-xs font-mono"
            aria-label="Filter by action"
          />
          <Select value={query.actor_type} onChange={(e) => setParam('actor_type', e.target.value)} options={['user', 'agent', 'system']} placeholder="All actors" className="w-36" aria-label="Filter by actor" />
          <Select value={query.result} onChange={(e) => setParam('result', e.target.value)} options={['success', 'failure', 'denied']} placeholder="All results" className="w-36" aria-label="Filter by result" />
        </div>

        {logs.isPending ? (
          <LoadingBlock />
        ) : logs.isError ? (
          <div className="p-5">
            <ErrorBanner error={logs.error} />
          </div>
        ) : logs.data.data.length === 0 ? (
          <EmptyState icon={<ScrollText className="size-5" />} title="No audit entries match" />
        ) : (
          <>
            <Table head={['', 'Time', 'Actor', 'Action', 'Target', 'Risk', 'Result']}>
              {logs.data.data.map((log) => (
                <Fragment key={log.id}>
                  <tr className="cursor-pointer hover:bg-slate-50" onClick={() => setExpanded(expanded === log.id ? null : log.id)}>
                    <Td className="w-8 pr-0 text-slate-400">{expanded === log.id ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}</Td>
                    <Td className="text-xs whitespace-nowrap text-slate-500">{formatDateTime(log.created_at)}</Td>
                    <Td>
                      <div className="text-sm text-slate-800">{log.user?.name ?? log.agent_id ?? titleCase(log.actor_type)}</div>
                      <div className="text-xs text-slate-500">{log.actor_type}</div>
                    </Td>
                    <Td className="font-mono text-xs text-slate-800">{log.action}</Td>
                    <Td className="text-xs text-slate-600">{log.target_type ?? '—'}</Td>
                    <Td>{log.risk_level ? <Badge tone={log.risk_level === 'low' ? 'info' : log.risk_level === 'medium' ? 'warning' : 'danger'}>{titleCase(log.risk_level)}</Badge> : <span className="text-slate-300">—</span>}</Td>
                    <Td>
                      <StatusBadge status={log.result} />
                    </Td>
                  </tr>
                  {expanded === log.id && (
                    <tr className="bg-slate-50/70">
                      <td colSpan={7} className="px-5 py-4">
                        <dl className="grid gap-3 text-xs sm:grid-cols-3">
                          <div>
                            <dt className="text-slate-500">Target id</dt>
                            <dd className="mt-0.5 font-mono break-all text-slate-800">{log.target_id ?? '—'}</dd>
                          </div>
                          <div>
                            <dt className="text-slate-500">Project id</dt>
                            <dd className="mt-0.5 font-mono break-all text-slate-800">{log.project_id ?? '—'}</dd>
                          </div>
                          <div>
                            <dt className="text-slate-500">IP address</dt>
                            <dd className="mt-0.5 font-mono text-slate-800">{log.ip_address ?? '—'}</dd>
                          </div>
                        </dl>
                        <pre className="mt-3 overflow-x-auto rounded-lg bg-slate-900 p-3 font-mono text-xs text-slate-100">{JSON.stringify(log.metadata, null, 2)}</pre>
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </Table>
            <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500">
              <span>{logs.data.meta.total} entries</span>
              <div className="flex gap-2">
                <Button size="sm" variant="secondary" disabled={query.page <= 1} onClick={() => setParam('page', String(query.page - 1))}>
                  Previous
                </Button>
                <Button size="sm" variant="secondary" disabled={query.page >= logs.data.meta.last_page} onClick={() => setParam('page', String(query.page + 1))}>
                  Next
                </Button>
              </div>
            </div>
          </>
        )}
      </Card>
    </>
  )
}
