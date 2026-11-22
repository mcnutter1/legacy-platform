import { useState } from 'react';
import { useCommand, useResourceList, useWsQuery } from '../api/hooks';
import { Card, FormError, Notice, PageHeader, Query, StatusBadge, Tabs } from '../components/ui';
import { ResourceSection } from '../components/resource';
import { date, humanize } from '../lib/format';

interface Prompt { code: string; message: string; subject?: string }
interface BreakGlass { id: string; binder_id: string; status: string; reason: string; requester_membership_id: string; verifications: number; expires_at: string | null; created_at: string }

export default function Governance() {
  const [tab, setTab] = useState('decisions');
  const prompts = useWsQuery<Prompt[]>(['gov-prompts'], 'governance/prompts');
  const bg = useWsQuery<BreakGlass[]>(['break-glass'], 'governance/break-glass', { enabled: tab === 'emergency' });
  const binders = useResourceList('emergency_binders', { limit: 50 }, tab === 'emergency');
  const request = useCommand<{ binder: string; reason: string }>((b) => `governance/binders/${b.binder}/break-glass`);
  const approve = useCommand<{ id: string; note: string }>((b) => `governance/break-glass/${b.id}/approve`);
  const deny = useCommand<{ id: string }>((b) => `governance/break-glass/${b.id}/deny`);
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Family" title="Governance" description="Decisions, meetings, goals and tasks; emergency binders with break-glass release; and continuity prompts." />
      <Query q={prompts}>
        {(p) => p.length > 0 ? (
          <Card title="Continuity checklist">
            <ul className="space-y-2 text-sm">{p.map((x, i) => <li key={i} className="flex items-start gap-3"><span className="badge bg-gold-tint text-warning">{humanize(x.code)}</span>{x.message}</li>)}</ul>
          </Card>
        ) : <Notice tone="success">No continuity gaps detected.</Notice>}
      </Query>
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'decisions', label: 'Decisions' }, { id: 'meetings', label: 'Meetings' }, { id: 'goals', label: 'Goals' }, { id: 'tasks', label: 'Tasks' }, { id: 'emergency', label: 'Emergency access' }]} />
      {tab === 'decisions' && <ResourceSection type="decisions" description="A durable record of what was decided, why and who participated." />}
      {tab === 'meetings' && <ResourceSection type="meetings" />}
      {tab === 'goals' && <ResourceSection type="family_goals" />}
      {tab === 'tasks' && <ResourceSection type="tasks" />}
      {tab === 'emergency' && (
        <div className="space-y-6">
          <Notice>Break-glass release requires a stated reason, the waiting period you set, and (if configured) approval by a second steward. Every step is audited and stewards are notified.</Notice>
          <ResourceSection type="emergency_binders" description="Contents is a JSON list of record references (e.g. [{&quot;type&quot;: &quot;documents&quot;, &quot;id&quot;: &quot;…&quot;}])." />
          <Card title="Request emergency access">
            <div className="flex flex-wrap gap-3">
              {(binders.data?.data ?? []).map((b) => (
                <button key={b.id} className="btn-secondary btn-sm" onClick={() => { const reason = prompt(`Why do you need access to "${b._title}"?`); if (reason) request.mutate({ binder: b.id, reason }); }}>{b._title}</button>
              ))}
            </div>
            <FormError error={request.error ?? approve.error ?? deny.error} />
          </Card>
          <Card title="Requests" pad={false}>
            <Query q={bg}>
              {(rows) => rows.length === 0 ? <p className="p-5 text-sm text-muted">No requests.</p> : (
                <table className="data-table">
                  <thead><tr><th>Requested</th><th>Reason</th><th>Status</th><th>Verifications</th><th>Expires</th><th /></tr></thead>
                  <tbody>{rows.map((r) => (
                    <tr key={r.id}>
                      <td className="num">{date(r.created_at)}</td><td>{r.reason}</td><td><StatusBadge status={r.status} /></td><td className="num">{r.verifications}</td><td className="num">{date(r.expires_at)}</td>
                      <td>{['pending', 'verified', 'waiting'].includes(r.status) && (
                        <div className="flex gap-2">
                          <button className="btn-secondary btn-sm" onClick={() => approve.mutate({ id: r.id, note: '' })}>Approve</button>
                          <button className="btn-danger btn-sm" onClick={() => deny.mutate({ id: r.id })}>Deny</button>
                        </div>
                      )}</td>
                    </tr>
                  ))}</tbody>
                </table>
              )}
            </Query>
          </Card>
        </div>
      )}
    </div>
  );
}
