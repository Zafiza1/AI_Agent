import { Bot, Cpu, User as UserIcon } from 'lucide-react'
import { formatDateTime, timeAgo, titleCase } from '../lib/format'
import type { AuditLog } from '../lib/types'
import { Badge, StatusBadge } from './ui'

const actorIcon = { user: UserIcon, agent: Bot, system: Cpu }

function describeAction(log: AuditLog): string {
  return titleCase(log.action.replace('.', ' '))
}

export function ActivityList({ logs }: { logs: AuditLog[] }) {
  return (
    <ul className="divide-y divide-slate-100">
      {logs.map((log) => {
        const Icon = actorIcon[log.actor_type]
        return (
          <li key={log.id} className="flex items-start gap-3 px-5 py-3">
            <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500">
              <Icon className="size-3.5" />
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-sm text-slate-800">
                <span className="font-medium">{log.user?.name ?? titleCase(log.actor_type)}</span>{' '}
                <span className="text-slate-600">{describeAction(log).toLowerCase()}</span>
              </p>
              <p className="mt-0.5 text-xs text-slate-500" title={formatDateTime(log.created_at)}>
                {timeAgo(log.created_at)}
                {log.target_type && <> · {log.target_type}</>}
              </p>
            </div>
            <div className="flex shrink-0 items-center gap-1.5">
              {log.risk_level && log.risk_level !== 'low' && <Badge tone={log.risk_level === 'medium' ? 'warning' : 'danger'}>{titleCase(log.risk_level)}</Badge>}
              {log.result !== 'success' && <StatusBadge status={log.result} />}
            </div>
          </li>
        )
      })}
    </ul>
  )
}
