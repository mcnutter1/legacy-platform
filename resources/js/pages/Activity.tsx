import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, ws } from '../api/client';
import { useCommand, useWsQuery } from '../api/hooks';
import type { Job } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, PageHeader, Query, StatusBadge, Tabs } from '../components/ui';
import { humanize, relative } from '../lib/format';

interface AuditRow { id: string; actor_type: string; membership_id: string | null; action: string; subject_type: string | null; subject_id: string | null; metadata: Record<string, unknown> | null; created_at: string; hash: string }

function AuditLog() {
  const { id } = useWorkspace();
  const [before, setBefore] = useState<string | null>(null);
  const q = useQuery<{ data: AuditRow[]; next_before: string | null }>({ queryKey: [id, 'audit', before], queryFn: () => api(ws(id, 'access/audit'), { query: { before } }) });
  return (
    <Card pad={false}>
      <Query q={q}>
        {(r) => (
          <>
            <table className="data-table">
              <thead><tr><th>When</th><th>Action</th><th>Subject</th><th>Details</th><th>Chain</th></tr></thead>
              <tbody>{r.data.map((e) => (
                <tr key={e.id}>
                  <td className="num whitespace-nowrap">{relative(e.created_at)}</td>
                  <td>{e.action}<span className="block text-xs text-muted">{e.actor_type}</span></td>
                  <td className="text-xs">{e.subject_type} {e.subject_id?.slice(-6)}</td>
                  <td className="max-w-xs truncate text-xs text-muted">{e.metadata ? JSON.stringify(e.metadata) : ''}</td>
                  <td><code className="text-xs text-muted">{e.hash?.slice(0, 8)}</code></td>
                </tr>
              ))}</tbody>
            </table>
            <div className="flex justify-end gap-2 p-4">
              {before && <button className="btn-secondary btn-sm" onClick={() => setBefore(null)}>Newest</button>}
              {r.next_before && <button className="btn-secondary btn-sm" onClick={() => setBefore(r.next_before)}>Older</button>}
            </div>
          </>
        )}
      </Query>
    </Card>
  );
}

function Jobs() {
  const q = useWsQuery<Job[]>(['jobs-list'], 'jobs', { refetchInterval: 5000 });
  const cancel = useCommand<{ id: string }>((b) => `jobs/${b.id}/cancel`);
  const retry = useCommand<{ id: string }>((b) => `jobs/${b.id}/retry`);
  return (
    <Card pad={false}>
      <Query q={q}>
        {(rows) => rows.length === 0 ? <p className="p-5 text-sm text-muted">No background work yet.</p> : (
          <table className="data-table">
            <thead><tr><th>Type</th><th>Status</th><th>Attempts</th><th>Started</th><th>Finished</th><th>Error</th><th /></tr></thead>
            <tbody>{rows.map((j) => (
              <tr key={j.id}>
                <td>{humanize(j.type.replace('.', ' '))}</td><td><StatusBadge status={j.status} /></td><td className="num">{j.attempts}</td>
                <td className="num">{relative(j.created_at)}</td><td className="num">{relative(j.finished_at)}</td><td className="max-w-xs truncate text-xs text-negative">{j.error}</td>
                <td>{['queued', 'retryable'].includes(j.status) && <button className="btn-ghost btn-sm" onClick={() => cancel.mutate({ id: j.id })}>Cancel</button>}
                  {['failed', 'timed_out'].includes(j.status) && <button className="btn-secondary btn-sm" onClick={() => retry.mutate({ id: j.id })}>Retry</button>}</td>
              </tr>
            ))}</tbody>
          </table>
        )}
      </Query>
    </Card>
  );
}

export default function Activity() {
  const [tab, setTab] = useState('audit');
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Admin" title="Activity" description="The tamper-evident audit trail (each event is hash-chained to the previous) and your background jobs." />
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'audit', label: 'Audit log' }, { id: 'jobs', label: 'My jobs' }]} />
      {tab === 'audit' ? <AuditLog /> : <Jobs />}
    </div>
  );
}
