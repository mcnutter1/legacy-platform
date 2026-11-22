import { useState } from 'react';
import { Link } from 'react-router';
import { useCommand, useResourceList, useWsQuery } from '../api/hooks';
import type { AnyRecord } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { SourceIcon } from './sources';
import { Card, Dialog, FormError, Money, Notice, Query, StatusBadge } from './ui';
import { date, humanize } from '../lib/format';

type Visibility = 'visible' | 'hidden' | 'ignored';
interface PairSide { id: string; name: string; institution: string | null; mask: string | null; kind: string; visibility: Visibility; balance: string | null; as_of: string | null;
  authoritative_connection_id: string | null; sources: { connection_id: string; label: string | null; provider: string | null }[] }
interface Pair { key: string; score: number; reasons: string[]; flagged: boolean; conflict_id: string | null; same_source: boolean; a: PairSide; b: PairSide }

const REASONS: Record<string, string> = { aggregate: 'Aggregate or total of other accounts', duplicate: 'Duplicate of another account', not_ours: 'Not ours / not relevant', closed: 'Closed', other: 'Other' };

export const VISIBILITY_LABEL: Record<Visibility, string> = { visible: 'Counted', hidden: 'Hidden', ignored: 'Ignored' };

/** Hide (keep syncing, leave out of totals) or ignore (stop syncing too) an account, or restore it. */
export function VisibilityButton({ account }: { account: AnyRecord }) {
  const [open, setOpen] = useState(false);
  const current = (account.visibility as Visibility) ?? 'visible';
  const [choice, setChoice] = useState<Visibility>(current === 'visible' ? 'hidden' : current);
  const [reason, setReason] = useState<string>((account.visibility_reason as string) ?? 'aggregate');
  const save = useCommand<{ visibility: Visibility; reason?: string | null }>(`accounts/${account.id}/visibility`);
  if (current !== 'visible') {
    return <button className="btn-secondary btn-sm" disabled={save.isPending} onClick={() => save.mutate({ visibility: 'visible' })}>Restore to totals</button>;
  }
  return (
    <>
      <button className="btn-secondary btn-sm" onClick={() => setOpen(true)}>Hide or ignore…</button>
      <Dialog open={open} onClose={() => setOpen(false)} title={`Leave “${String(account.name)}” out`}>
        <form className="space-y-4 text-sm" onSubmit={(e) => { e.preventDefault(); save.mutate({ visibility: choice, reason }, { onSuccess: () => setOpen(false) }); }}>
          <fieldset className="space-y-2">
            <legend className="mb-1 font-medium">What should happen</legend>
            <label className="flex items-start gap-2"><input type="radio" name="vis" checked={choice === 'hidden'} onChange={() => setChoice('hidden')} />
              <span><strong>Hide</strong> — keep syncing it, but leave it out of net worth, investments, plans and the accounts list.</span></label>
            <label className="flex items-start gap-2"><input type="radio" name="vis" checked={choice === 'ignored'} onChange={() => setChoice('ignored')} />
              <span><strong>Ignore</strong> — also stop importing balances, holdings and transactions for it from every source. Use for aggregates and accounts that aren't yours.</span></label>
          </fieldset>
          <label className="field"><span>Reason</span>
            <select value={reason} onChange={(e) => setReason(e.target.value)}>{Object.entries(REASONS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></label>
          <p className="text-xs text-muted">Nothing is deleted; history stays and you can restore it at any time. A duplicate is usually better merged — use “Find duplicates”.</p>
          <FormError error={save.error} />
          <div className="flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
            <button className="btn-primary" disabled={save.isPending}>{choice === 'hidden' ? 'Hide account' : 'Ignore account'}</button></div>
        </form>
      </Dialog>
    </>
  );
}

export function VisibilityNotice({ account }: { account: AnyRecord }) {
  const v = account.visibility as Visibility;
  if (!v || v === 'visible') return null;
  return <Notice tone="warning">{v === 'hidden' ? 'Hidden: still synced, but not counted in net worth, investments or plans.' : 'Ignored: sources no longer update this account and it is not counted anywhere.'}
    {account.visibility_reason ? ` Reason: ${REASONS[String(account.visibility_reason)] ?? humanize(String(account.visibility_reason))}.` : ''}</Notice>;
}

function Side({ s }: { s: PairSide }) {
  const { path } = useWorkspace();
  return (
    <div className="min-w-0 flex-1 rounded-lg border border-line p-3">
      <Link className="link font-medium" to={path(`accounts/${s.id}`)}>{s.name}</Link>
      {s.visibility !== 'visible' && <StatusBadge status="archived" label={VISIBILITY_LABEL[s.visibility]} />}
      <p className="text-xs text-muted">{[s.institution, s.mask ? `••${s.mask}` : null, humanize(s.kind)].filter(Boolean).join(' · ')}</p>
      <p className="mt-1 text-sm"><Money value={s.balance} cents />{s.as_of ? <span className="text-xs text-muted"> on {date(s.as_of)}</span> : null}</p>
      <p className="mt-1 flex flex-wrap gap-1 text-xs text-muted">{s.sources.length === 0 ? 'Manual' : s.sources.map((c) => (
        <span key={c.connection_id} className="inline-flex items-center gap-1"><SourceIcon provider={c.provider ?? 'manual'} size={14} />{c.label}</span>))}</p>
    </div>
  );
}

/** Link any other account into this one, e.g. the Snowball and Monarch copies of the same brokerage account. */
function CombinePicker({ accountId }: { accountId: string }) {
  const accounts = useResourceList('accounts', { limit: 200 });
  const merge = useCommand<{ from: string; into_account_id: string }>((b) => `accounts/${b.from}/merge`);
  const [other, setOther] = useState('');
  const options = (accounts.data?.data ?? []).filter((a) => a.id !== accountId);
  const name = options.find((a) => a.id === other)?._title;
  return (
    <form className="flex flex-wrap items-end gap-3 border-t border-line px-5 py-4 text-sm" onSubmit={(e) => {
      e.preventDefault();
      if (other && confirm(`Combine “${name}” into this account? Its sources, balances, holdings, transactions and lots move here and it is archived. Both sources then feed this account.`)) {
        merge.mutate({ from: other, into_account_id: accountId }, { onSuccess: () => setOther('') });
      }
    }}>
      <label className="field min-w-64 flex-1"><span>Combine another account into this one</span>
        <select value={other} onChange={(e) => setOther(e.target.value)}>
          <option value="">— Choose an account —</option>
          {options.map((a) => <option key={a.id} value={a.id}>{String(a._title)}{a.institution ? ` · ${String(a.institution)}` : ''}{a.visibility && a.visibility !== 'visible' ? ` (${String(a.visibility)})` : ''}</option>)}
        </select></label>
      <button className="btn-secondary" disabled={!other || merge.isPending}>Combine</button>
      <div className="w-full"><FormError error={merge.error} /></div>
    </form>
  );
}

/** Suggested duplicate pairs with merge / ignore / not-a-duplicate actions. Pass accountId to focus on one account. */
export function DuplicatesPanel({ accountId }: { accountId?: string }) {
  const q = useWsQuery<Pair[]>(['account-duplicates', accountId ?? 'all'], 'account-duplicates', { query: accountId ? { account_id: accountId } : undefined });
  const merge = useCommand<{ from: string; into_account_id: string }>((b) => `accounts/${b.from}/merge`);
  const notDup = useCommand<{ id: string; other_account_id: string }>((b) => `accounts/${b.id}/not-duplicate`);
  const vis = useCommand<{ id: string; visibility: Visibility; reason: string }>((b) => `accounts/${b.id}/visibility`);
  const [confirm, setConfirm] = useState<{ keep: PairSide; drop: PairSide } | null>(null);
  const busy = merge.isPending || notDup.isPending || vis.isPending;
  return (
    <Card title={accountId ? 'Possible duplicates of this account' : 'Possible duplicate accounts'}
      subtitle="Matched on account number ending, institution, name, type and balance. Merging keeps one account and moves every source, balance, holding and transaction into it." pad={false}>
      <Query q={q}>
        {(pairs) => pairs.length === 0 ? <p className="p-5 text-sm text-muted">{accountId ? 'No likely duplicates.' : 'No likely duplicates found.'}</p> : (
          <ul className="divide-y divide-line">
            {pairs.map((p) => (
              <li key={p.key} className="space-y-3 px-5 py-4 text-sm">
                <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
                  {p.flagged && <StatusBadge status="needs_review" label="Flagged by sync" />}
                  <span>Match: {p.reasons.map(humanize).join(', ') || 'similar'}</span>
                </div>
                <div className="flex flex-col gap-3 sm:flex-row"><Side s={p.a} /><Side s={p.b} /></div>
                {p.same_source ? (
                  <div className="space-y-2">
                    <p className="text-xs text-ink-soft">The same source reports both separately, so they can't be merged. If one is a total or a duplicate login, ignore it.</p>
                    <div className="flex flex-wrap gap-2">
                      <button className="btn-secondary btn-sm" disabled={busy} onClick={() => vis.mutate({ id: p.a.id, visibility: 'ignored', reason: 'aggregate' })}>Ignore “{p.a.name}”</button>
                      <button className="btn-secondary btn-sm" disabled={busy} onClick={() => vis.mutate({ id: p.b.id, visibility: 'ignored', reason: 'aggregate' })}>Ignore “{p.b.name}”</button>
                      <button className="btn-ghost btn-sm" disabled={busy} onClick={() => notDup.mutate({ id: p.a.id, other_account_id: p.b.id })}>Not duplicates</button>
                    </div>
                  </div>
                ) : (
                  <div className="flex flex-wrap gap-2">
                    <button className="btn-primary btn-sm" disabled={busy} onClick={() => setConfirm({ keep: p.a, drop: p.b })}>Keep “{p.a.name}”, merge the other in</button>
                    <button className="btn-secondary btn-sm" disabled={busy} onClick={() => setConfirm({ keep: p.b, drop: p.a })}>Keep “{p.b.name}”</button>
                    <button className="btn-ghost btn-sm" disabled={busy} onClick={() => notDup.mutate({ id: p.a.id, other_account_id: p.b.id })}>Not duplicates</button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
      </Query>
      <div className="px-5 pb-4"><FormError error={merge.error ?? notDup.error ?? vis.error} /></div>
      {accountId && <CombinePicker accountId={accountId} />}
      <Dialog open={!!confirm} onClose={() => setConfirm(null)} title="Merge duplicate account">
        {confirm && (
          <div className="space-y-4 text-sm">
            <p>Keep <strong>{confirm.keep.name}</strong>. Every source link, balance, holding, transaction and lot from <strong>{confirm.drop.name}</strong> moves into it, and the duplicate is archived. Future syncs from both sources update the kept account.</p>
            <div className="flex justify-end gap-2">
              <button className="btn-secondary" onClick={() => setConfirm(null)}>Cancel</button>
              <button className="btn-primary" disabled={merge.isPending} onClick={() => merge.mutate({ from: confirm.drop.id, into_account_id: confirm.keep.id }, { onSuccess: () => setConfirm(null) })}>Merge</button>
            </div>
          </div>
        )}
      </Dialog>
    </Card>
  );
}
