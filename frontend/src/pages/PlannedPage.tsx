import { CalendarClock, CheckCircle2 } from 'lucide-react'
import { Navigate, useParams } from 'react-router-dom'
import { Badge, Card, PageHeader } from '../components/ui'
import { roadmapItem } from '../lib/roadmap'

/**
 * Placeholder for areas delivered in later phases. It describes the capability
 * and its phase; it never shows sample or fabricated data.
 */
export function PlannedPage() {
  const { feature = '' } = useParams()
  const item = roadmapItem(feature)

  if (!item) return <Navigate to="/" replace />

  return (
    <>
      <PageHeader title={item.title} description={item.summary} actions={<Badge tone="violet">Phase {item.phase}</Badge>} />
      <Card className="max-w-2xl p-6">
        <div className="flex items-start gap-4">
          <span className="rounded-xl bg-violet-50 p-3 text-violet-600">
            <CalendarClock className="size-5" />
          </span>
          <div>
            <h2 className="text-sm font-semibold text-slate-900">Planned for Phase {item.phase}</h2>
            <p className="mt-1 text-sm text-slate-500">
              The foundation it depends on (organizations, RBAC, projects, environments, secrets and audit logging) is in place. This
              area will include:
            </p>
            <ul className="mt-4 space-y-2">
              {item.capabilities.map((capability) => (
                <li key={capability} className="flex items-start gap-2 text-sm text-slate-700">
                  <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-slate-300" /> {capability}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </Card>
    </>
  )
}
