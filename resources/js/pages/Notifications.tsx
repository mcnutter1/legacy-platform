import { Link } from 'react-router';
import { useCommand, useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { Card, Empty, PageHeader, Query } from '../components/ui';
import { date, relative } from '../lib/format';

interface Note { id: string; kind: string; title: string; body: string | null; link: string | null; severity: string; due_on: string | null; read_at: string | null; created_at: string }

export default function Notifications() {
  const { path } = useWorkspace();
  const q = useWsQuery<{ items: Note[]; unread: number }>(['notifications', 'list'], 'notifications');
  const mark = useCommand<{ id: string; action: string; days?: number }>((b) => `notifications/${b.id}`);
  return (
    <div className="space-y-6">
      <PageHeader title="Notifications" actions={<button className="btn-secondary btn-sm" onClick={() => mark.mutate({ id: 'all', action: 'read' })}>Mark all read</button>} />
      <Card pad={false}>
        <Query q={q}>
          {(d) => d.items.length === 0 ? <div className="p-6"><Empty title="No notifications" /></div> : (
            <ul className="divide-y divide-line">
              {d.items.map((n) => (
                <li key={n.id} className={`flex flex-wrap items-start justify-between gap-3 px-5 py-4 ${n.read_at ? '' : 'bg-pine-tint/30'}`}>
                  <div className="min-w-0">
                    <p className={n.read_at ? '' : 'font-medium'}>{n.severity === 'high' && <span className="mr-2 text-negative" aria-label="High priority">●</span>}{n.title}</p>
                    {n.body && <p className="mt-0.5 text-sm text-ink-soft">{n.body}</p>}
                    <p className="mt-1 text-xs text-muted">{relative(n.created_at)}{n.due_on ? ` · due ${date(n.due_on)}` : ''}</p>
                  </div>
                  <div className="flex gap-2">
                    {n.link && <Link className="btn-secondary btn-sm" to={n.link.startsWith('/') ? n.link : path(n.link)} onClick={() => mark.mutate({ id: n.id, action: 'read' })}>Open</Link>}
                    {!n.read_at && <button className="btn-ghost btn-sm" onClick={() => mark.mutate({ id: n.id, action: 'read' })}>Mark read</button>}
                    <button className="btn-ghost btn-sm" onClick={() => mark.mutate({ id: n.id, action: 'snooze', days: 7 })}>Snooze 7d</button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Query>
      </Card>
    </div>
  );
}
