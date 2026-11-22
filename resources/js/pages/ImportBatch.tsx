import { useState } from 'react';
import { useParams } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ws } from '../api/client';
import { useCommand, useResourceList, useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { Card, FormError, KeyValue, Money, Notice, PageHeader, Query, Stat, StatusBadge, Tabs } from '../components/ui';
import { date, humanize } from '../lib/format';

interface Batch { id: string; provider: string; status: string; row_count: number; stats: { matching?: Record<string, number>; issue_counts?: Record<string, number>; schema_drift?: { missing_columns: string[]; unexpected_columns: string[] }; suggested_mapping?: { field: string; column: string; confidence: number; reason: string }[]; error?: string } | null; coverage: { from: string; to: string; accounts: string[] } | null; reconciliation: { accounts: { account_id: string; transactions: number; net_amount: string; from: string; to: string; latest_balance_snapshot: { as_of: string; balance: string } | null }[]; unpaired_transfers: number } | null; committed_at: string | null }
interface Row { id: string; row_no: number; state: string; match_type: string | null; matched_transaction_id: string | null; normalized: Record<string, string | null>; issues: string[] }

export default function ImportBatch() {
  const { batch = '' } = useParams();
  const { id } = useWorkspace();
  const qc = useQueryClient();
  const [tab, setTab] = useState('needs_review');
  const b = useWsQuery<Batch>(['import', batch], `imports/${batch}`);
  const rows = useQuery<{ data: Row[] }>({ queryKey: [id, 'import-rows', batch, tab], queryFn: () => api(ws(id, `imports/${batch}/rows`), { query: { state: tab === 'all' ? undefined : tab, limit: 200 } }) });
  const accounts = useResourceList('accounts', { limit: 200 });
  const commit = useCommand(`imports/${batch}/commit`);
  const reverse = useCommand<{ reason: string }>(`imports/${batch}/reverse`);
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const resolve = async (row: Row, action: string, extra: Record<string, unknown> = {}) => {
    await api(ws(id, `imports/${batch}/rows/${row.id}`), { body: { action, ...extra } });
    void qc.invalidateQueries({ queryKey: [id] });
  };
  return (
    <Query q={b}>
      {(d) => (
        <div className="space-y-6">
          <PageHeader eyebrow={`Import · ${humanize(d.provider)}`} title={`Batch ${d.id.slice(-6)}`} description={d.coverage ? `${date(d.coverage.from)} – ${date(d.coverage.to)} · ${d.coverage.accounts.join(', ')}` : undefined}
            actions={<>
              <StatusBadge status={d.status} />
              {d.status === 'previewed' && <button className="btn-primary" disabled={commit.isPending} onClick={() => commit.mutate({})}>Commit batch</button>}
              {d.status === 'committed' && <button className="btn-danger" onClick={() => { const reason = prompt('Why reverse this batch?'); if (reason) reverse.mutate({ reason }); }}>Reverse batch</button>}
            </>} />
          <FormError error={commit.error ?? reverse.error} />
          {d.status === 'failed' && d.stats?.schema_drift && (
            <Notice tone="danger">
              The file does not match the expected layout. Missing: {d.stats.schema_drift.missing_columns.join(', ') || 'none'}. Unexpected: {d.stats.schema_drift.unexpected_columns.join(', ') || 'none'}.
              {d.stats.suggested_mapping?.length ? <> Suggested mapping (review before approving a profile): {d.stats.suggested_mapping.map((s) => `${s.field} ← "${s.column}" (${Math.round(s.confidence * 100)}%)`).join('; ')}.</> : null}
            </Notice>
          )}
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <Stat label="Rows" value={d.row_count} />
            {Object.entries(d.stats?.matching ?? {}).map(([k, v]) => <Stat key={k} label={humanize(k)} value={v} />)}
          </div>
          {d.reconciliation && (
            <Card title="Reconciliation" subtitle={`${d.reconciliation.unpaired_transfers} unpaired transfer(s)`}>
              <table className="data-table"><thead><tr><th>Account</th><th className="text-right">Transactions</th><th className="text-right">Net</th><th>Period</th><th>Latest statement balance</th></tr></thead>
                <tbody>{d.reconciliation.accounts.map((a) => (
                  <tr key={a.account_id}><td>{accounts.data?.data.find((x) => x.id === a.account_id)?._title ?? a.account_id}</td><td className="num text-right">{a.transactions}</td><td className="text-right"><Money value={a.net_amount} cents /></td>
                    <td className="num">{date(a.from)} – {date(a.to)}</td><td>{a.latest_balance_snapshot ? <><Money value={a.latest_balance_snapshot.balance} cents /> on {date(a.latest_balance_snapshot.as_of)}</> : <span className="text-muted">none</span>}</td></tr>
                ))}</tbody></table>
            </Card>
          )}
          <Card pad={false}>
            <div className="px-5 pt-4 sm:px-6"><Tabs value={tab} onChange={setTab} tabs={[{ id: 'needs_review', label: 'Needs review' }, { id: 'mapped', label: 'Ready' }, { id: 'reconciled', label: 'Matched / committed' }, { id: 'superseded', label: 'Skipped' }, { id: 'all', label: 'All' }]} /></div>
            <Query q={rows}>
              {(r) => r.data.length === 0 ? <p className="px-6 pb-6 text-sm text-muted">No rows in this view.</p> : (
                <div className="table-wrap">
                  <table className="data-table">
                    <thead><tr><th>#</th><th>Date</th><th>Description</th><th>Account</th><th className="text-right">Amount</th><th>Match</th><th>Issues</th>{d.status === 'previewed' && <th>Action</th>}</tr></thead>
                    <tbody>{r.data.map((row) => (
                      <tr key={row.id}>
                        <td className="num text-muted">{row.row_no}</td>
                        <td className="num">{row.normalized.posted_on ?? '—'}</td>
                        <td>{row.normalized.description || row.normalized.merchant}<br /><span className="text-xs text-muted">{row.normalized.category}</span></td>
                        <td>{row.normalized.account_label}</td>
                        <td className="text-right"><Money value={row.normalized.amount} cents /></td>
                        <td>{row.match_type && <StatusBadge status={row.match_type === 'duplicate' ? 'reconciled' : row.match_type === 'ambiguous' ? 'needs_review' : 'draft'} label={humanize(row.match_type)} />}</td>
                        <td className="text-xs text-negative">{row.issues.map(humanize).join(', ')}</td>
                        {d.status === 'previewed' && (
                          <td>
                            {row.state === 'needs_review' && (
                              <div className="flex flex-wrap gap-1">
                                {row.issues.some((i) => i.includes('account')) && (
                                  <select className="input min-h-9 max-w-40 text-xs" aria-label="Map to account" value={mapping[row.id] ?? ''} onChange={(e) => setMapping({ ...mapping, [row.id]: e.target.value })}>
                                    <option value="">Map account…</option>{(accounts.data?.data ?? []).map((a) => <option key={a.id} value={a.id}>{a._title}</option>)}
                                  </select>
                                )}
                                <button className="btn-secondary btn-sm" onClick={() => resolve(row, 'accept_new', mapping[row.id] ? { account_id: mapping[row.id] } : {})}>Accept</button>
                                <button className="btn-ghost btn-sm" onClick={() => resolve(row, 'skip')}>Skip</button>
                              </div>
                            )}
                          </td>
                        )}
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              )}
            </Query>
          </Card>
          {d.committed_at && <KeyValue items={[['Committed', date(d.committed_at)]]} />}
        </div>
      )}
    </Query>
  );
}
