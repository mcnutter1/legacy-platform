import { useState } from 'react';
import { Link } from 'react-router';
import { useCommand, useResourceList, useWsQuery } from '../api/hooks';
import type { AnyRecord } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { providerName, SourceIcon } from './sources';
import { Card, Dialog, FormError, Money, Query, StatusBadge } from './ui';
import { date, humanize, money, num, relative } from '../lib/format';

interface Observed { connection_id?: string; account_id?: string; provider?: string; label?: string; value: string | null; as_of?: string | null; score?: number; reasons?: string[] }

/** Open conflicts across sources: balances/holdings that disagree and accounts that may be duplicates. */
export function Conflicts({ accountId }: { accountId?: string }) {
  const { path } = useWorkspace();
  const list = useResourceList('source_discrepancies', { filter: { status: 'open', ...(accountId ? { entity_id: accountId } : {}) }, limit: 100 });
  const accounts = useResourceList('accounts', { limit: 200 });
  const resolve = useCommand<{ id: string; action: string; prefer_connection_id?: string }>((b) => `source-conflicts/${b.id}/resolve`);
  const merge = useCommand<{ from: string; into_account_id: string }>((b) => `accounts/${b.from}/merge`);
  const name = (id: string | null | undefined) => accounts.data?.data.find((a) => a.id === id)?._title ?? 'account';
  const [confirmMerge, setConfirmMerge] = useState<AnyRecord | null>(null);
  return (
    <Card title="Source conflicts" subtitle="Where sources disagree about the same account, or an imported account may duplicate one you already have." pad={false}>
      <Query q={list}>
        {(p) => p.data.length === 0 ? <p className="p-5 text-sm text-muted">No open conflicts.</p> : (
          <ul className="divide-y divide-line">
            {p.data.map((d) => {
              const obs = (d.observed as Observed[] | null) ?? [];
              return (
                <li key={d.id} className="space-y-2 px-5 py-4 text-sm">
                  <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge status="needs_review" label={humanize(String(d.kind))} />
                    {!accountId && <Link className="link font-medium" to={path(`accounts/${d.entity_id}`)}>{name(d.entity_id as string)}</Link>}
                    {d.field && d.field !== 'balance' ? <code className="text-xs">{String(d.field)}</code> : null}
                    {d.difference ? <span className="text-xs text-muted">difference {d.kind === 'balance' ? money(String(d.difference)) : num(String(d.difference), 4)}</span> : null}
                    <span className="text-xs text-muted">seen {relative(d.last_seen_at as string)}</span>
                  </div>
                  {d.kind === 'possible_duplicate' ? (
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-ink-soft">Created from {providerName(obs[0]?.provider ?? '')}; looks like <strong>{obs[1]?.value}</strong> ({(obs[1]?.reasons ?? []).map(humanize).join(', ')}).</span>
                      <button className="btn-primary btn-sm" onClick={() => setConfirmMerge(d)}>Merge into {obs[1]?.value}</button>
                      <button className="btn-ghost btn-sm" onClick={() => resolve.mutate({ id: d.id, action: 'ignore' })}>Not a duplicate</button>
                    </div>
                  ) : (
                    <div className="flex flex-wrap items-center gap-2">
                      {obs.map((o) => (
                        <span key={o.connection_id ?? o.label} className="inline-flex items-center gap-1.5 rounded-lg border border-line px-2 py-1">
                          <SourceIcon provider={o.provider ?? 'manual'} size={16} />{o.label}: <strong>{o.value === null ? 'missing' : d.kind === 'balance' ? money(o.value) : num(o.value, 4)}</strong>
                          {o.connection_id && <button className="btn-ghost btn-sm" onClick={() => resolve.mutate({ id: d.id, action: 'resolve', prefer_connection_id: o.connection_id })}>Trust this source</button>}
                        </span>
                      ))}
                      <button className="btn-ghost btn-sm" onClick={() => resolve.mutate({ id: d.id, action: 'ignore' })}>Ignore</button>
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </Query>
      <div className="px-5 pb-4"><FormError error={resolve.error ?? merge.error} /></div>
      <Dialog open={!!confirmMerge} onClose={() => setConfirmMerge(null)} title="Merge duplicate account">
        {confirmMerge && (
          <div className="space-y-4 text-sm">
            <p>Move every source link, balance, holding, transaction and lot from <strong>{name(confirmMerge.entity_id as string)}</strong> into <strong>{(confirmMerge.observed as Observed[])[1]?.value}</strong>, then archive the duplicate. This is recorded in the audit log.</p>
            <div className="flex justify-end gap-2">
              <button className="btn-secondary" onClick={() => setConfirmMerge(null)}>Cancel</button>
              <button className="btn-primary" disabled={merge.isPending} onClick={() => merge.mutate({ from: confirmMerge.entity_id as string, into_account_id: confirmMerge.candidate_id as string }, { onSuccess: () => setConfirmMerge(null) })}>Merge</button>
            </div>
          </div>
        )}
      </Dialog>
    </Card>
  );
}

interface SourcesResp {
  authoritative_connection_id: string | null;
  roles: Record<Role, string | null>;
  explicit_roles: Partial<Record<Role, string>>;
  sources: { connection_id: string; provider: string; label: string; status: string | null; external_name: string | null; match_method: string; last_seen_at: string | null;
    last_values: { balance?: string | null; as_of?: string | null; holdings?: Record<string, string> | null } | null; authoritative: boolean }[];
}

type Role = 'balance' | 'holdings' | 'transactions';
const ROLES: Role[] = ['balance', 'holdings', 'transactions'];

/** Which sources report an account, what each last said, and which one supplies balances, holdings and transactions. */
export function AccountSources({ accountId }: { accountId: string }) {
  const q = useWsQuery<SourcesResp>(['account-sources', accountId], `accounts/${accountId}/sources`);
  const authority = useCommand<{ connection_id: string | null }>(`accounts/${accountId}/authority`);
  const role = useCommand<{ role: Role; connection_id: string | null }>(`accounts/${accountId}/source-role`);
  return (
    <div className="space-y-6">
      <Card title="Sources" subtitle="When several sources report this account, each kind of data comes from one of them. By default the primary source supplies everything it reports and another source fills in holdings or transactions the primary lacks. The others are compared for conflicts." pad={false}>
        <Query q={q}>
          {(d) => d.sources.length === 0 ? <p className="p-5 text-sm text-muted">This account is maintained manually or by file imports.</p> : (
            <div className="table-wrap">
              <table className="data-table">
                <caption className="sr-only">Sources for this account and which data each supplies</caption>
                <thead><tr><th>Source</th><th>Reported as</th><th className="text-right">Balance</th><th className="text-right">Positions</th><th>Last seen</th>
                  <th>Primary</th>{ROLES.map((r) => <th key={r}>{humanize(r)} from</th>)}</tr></thead>
                <tbody>{d.sources.map((s) => (
                  <tr key={s.connection_id}>
                    <td><span className="inline-flex items-center gap-2"><SourceIcon provider={s.provider} size={18} />{s.label}</span>
                      <span className="block text-xs text-muted">{humanize(s.match_method.split(':')[0] ?? '')}{s.match_method.includes(':') ? ` (${s.match_method.split(':')[1]?.split(',').map(humanize).join(', ')})` : ''}</span></td>
                    <td>{s.external_name ?? '—'}</td>
                    <td className="text-right"><Money value={s.last_values?.balance ?? null} cents /><span className="block text-xs text-muted">{date(s.last_values?.as_of ?? null)}</span></td>
                    <td className="num text-right">{s.last_values?.holdings ? Object.keys(s.last_values.holdings).length : '—'}</td>
                    <td className="text-xs">{relative(s.last_seen_at)}</td>
                    <td><input type="radio" name={`authority-${accountId}`} aria-label={`Make ${s.label} the primary source`} checked={s.authoritative} disabled={authority.isPending}
                      onChange={() => authority.mutate({ connection_id: s.connection_id })} /></td>
                    {ROLES.map((r) => (
                      <td key={r}><input type="radio" name={`${r}-${accountId}`} aria-label={`${humanize(r)} from ${s.label}`} checked={d.roles[r] === s.connection_id}
                        disabled={role.isPending} onChange={() => role.mutate({ role: r, connection_id: s.connection_id })} />
                        {d.roles[r] === s.connection_id && <span className="ml-1 text-xs text-muted">{d.explicit_roles[r] ? 'chosen' : 'auto'}</span>}</td>
                    ))}
                  </tr>
                ))}</tbody>
              </table>
              {ROLES.some((r) => d.explicit_roles[r]) && (
                <div className="flex flex-wrap gap-2 px-5 py-3 text-xs">
                  {ROLES.filter((r) => d.explicit_roles[r]).map((r) => (
                    <button key={r} className="btn-ghost btn-sm" disabled={role.isPending} onClick={() => role.mutate({ role: r, connection_id: null })}>Pick {r} source automatically</button>
                  ))}
                </div>
              )}
            </div>
          )}
        </Query>
        <div className="px-5 pb-4"><p className="pt-2 text-xs text-muted">Changes apply from the next sync (Schedules → Run now to apply immediately).</p><FormError error={authority.error ?? role.error} /></div>
      </Card>
      <Conflicts accountId={accountId} />
    </div>
  );
}
