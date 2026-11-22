import { useState } from 'react';
import { Link } from 'react-router';
import { useCommand, useResourceList } from '../api/hooks';
import type { AnyRecord } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, Empty, FormError, PageHeader, Query, StatusBadge, Tabs } from '../components/ui';
import { date, humanize, relative } from '../lib/format';
import { recordPath } from '../lib/paths';

const SEVERITY: Record<string, string> = { high: 'failed', normal: 'needs_review', low: 'draft' };

function Item({ item }: { item: AnyRecord }) {
  const { path } = useWorkspace();
  const resolve = useCommand<{ status: string; note?: string }>(`review/${item.id}/resolve`);
  const evidence = (item.evidence ?? {}) as Record<string, unknown>;
  const target = item.subject_type && item.subject_id ? recordPath(String(item.subject_type), String(item.subject_id)) : null;
  return (
    <li className="px-5 py-4 sm:px-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-medium">{String(item.title)}</p>
          <p className="mt-0.5 text-sm text-ink-soft">{String(item.reason ?? '')}</p>
          <p className="mt-1 text-xs text-muted">{humanize(String(item.domain))} · {humanize(String(item.kind))} · {relative(item.created_at as string)}{item.due_on ? ` · due ${date(item.due_on as string)}` : ''}</p>
          {Object.keys(evidence).length > 0 && (
            <details className="mt-2 text-xs"><summary className="cursor-pointer text-pine">Evidence</summary><pre className="mt-1 max-h-48 overflow-auto rounded bg-sunken p-2">{JSON.stringify(evidence, null, 2)}</pre></details>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={SEVERITY[String(item.severity)] ?? 'draft'} label={humanize(String(item.severity))} />
          {target && <Link className="btn-secondary btn-sm" to={path(target)}>Open</Link>}
          {item.status === 'open' && <>
            <button className="btn-primary btn-sm" disabled={resolve.isPending} onClick={() => resolve.mutate({ status: 'resolved' })}>{item.kind === 'carryforward_proposal' ? 'Confirm' : 'Resolve'}</button>
            <button className="btn-ghost btn-sm" disabled={resolve.isPending} onClick={() => resolve.mutate({ status: 'dismissed', note: prompt('Reason for dismissing (optional)') ?? '' })}>Dismiss</button>
          </>}
        </div>
      </div>
      <FormError error={resolve.error} />
    </li>
  );
}

export default function Review() {
  const [tab, setTab] = useState('open');
  const [domain, setDomain] = useState('');
  const q = useResourceList('review_items', { filter: { status: tab, domain }, limit: 100 });
  const { me } = useWorkspace();
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Workflow" title="Review inbox" description="Everything that needs a person: extracted values to confirm, import exceptions, carryforwards, estate issues and data-quality flags. Nothing changes your records until you act." />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Tabs value={tab} onChange={setTab} tabs={[{ id: 'open', label: 'Open' }, { id: 'resolved', label: 'Resolved' }, { id: 'dismissed', label: 'Dismissed' }]} />
        <select aria-label="Filter by area" className="input max-w-48" value={domain} onChange={(e) => setDomain(e.target.value)}>
          <option value="">All areas</option>{Object.entries(me.domains).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </div>
      <Card pad={false}>
        <Query q={q}>
          {(p) => p.data.length === 0 ? <div className="p-6"><Empty title={tab === 'open' ? 'All caught up' : 'Nothing here'}>{tab === 'open' ? 'New items appear when documents, imports or calculations need a decision.' : null}</Empty></div> : (
            <ul className="divide-y divide-line">{p.data.map((i) => <Item key={i.id} item={i} />)}</ul>
          )}
        </Query>
      </Card>
    </div>
  );
}
