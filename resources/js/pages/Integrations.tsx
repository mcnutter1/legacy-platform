import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { api, ws } from '../api/client';
import { useCommand, useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { useScopes } from '../components/resource';
import { JobStatus } from '../components/security';
import { providerName, SourceIcon } from '../components/sources';
import { Conflicts } from '../components/sourcesPanel';
import { Card, Dialog, Empty, FormError, Money, Notice, PageHeader, Query, StatusBadge } from '../components/ui';
import { date, humanize, num, relative } from '../lib/format';

interface Instructions { summary: string; steps: string[]; links: { label: string; url: string }[]; notes: string[] }
interface ProviderInfo { provider: string; label: string; auth_kind: 'oauth' | 'api_key'; enabled: boolean; default_url: string; instructions: Instructions; redirect_uri: string | null }
interface PlanRow { external_id: string; name: string; institution: string | null; kind: string; mask: string | null; balance: string | null; currency: string; holdings: number; transactions: number;
  action: 'new' | 'link' | 'linked' | 'possible_duplicate'; account_id: string | null; candidate: { id: string; name: string; score: number; reasons: string[] } | null; authoritative: boolean }
interface Connection {
  id: string; scope_id: string; provider: string; label: string; mode: string; status: string; auth_kind: string | null; server_url: string | null; authorized: boolean;
  credential_hint: string | null; credential_set_at: string | null; token_expires_at: string | null; approved_at: string | null; last_sync_at: string | null; last_success_at: string | null;
  last_error: string | null; last_job_id: string | null; sync_interval_hours: number; options: { import_transactions?: boolean; auto_commit_transactions?: boolean; transactions_days?: number };
  tool_map: Record<string, string | null> | null; last_sync_stats: Record<string, unknown> | null; linked_accounts: number; edit_version: number;
  tool_catalog?: { name: string; description: string | null }[] | null;
  last_preview?: { accounts: PlanRow[]; issues: string[]; tool_map: Record<string, string | null>; fetched_at: string; totals: Record<string, number> } | null;
}
interface Overview { providers: ProviderInfo[]; connections: Connection[]; open_conflicts: number }

export default function Integrations() {
  const q = useWsQuery<Overview>(['integrations'], 'integrations');
  const [params] = useSearchParams();
  const [adding, setAdding] = useState<ProviderInfo | null>(null);
  const [open, setOpen] = useState<string | null>(params.get('connected'));
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Integrations"
        description="Live, read-only connections to Monarch Money and Snowball Analytics over their official MCP servers. Every synced record shows where it came from; accounts reported by more than one source are de-duplicated and disagreements are flagged for review." />
      {params.get('connected') && <Notice tone="success">Authorization complete. Run Test, then Preview, then approve the mapping.</Notice>}
      <Query q={q}>
        {(d) => (
          <>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              {d.providers.map((p) => (
                <Card key={p.provider} title={<span className="flex items-center gap-2"><SourceIcon provider={p.provider} size={24} />{p.label}</span>}
                  subtitle={p.auth_kind === 'oauth' ? 'OAuth · read-only scope' : 'Personal MCP key · read-only'}
                  actions={<button className="btn-primary btn-sm" disabled={!p.enabled} onClick={() => setAdding(p)}>Add connection</button>}>
                  <p className="text-sm text-ink-soft">{p.instructions.summary}</p>
                  {!p.enabled && <p className="mt-2 text-xs text-warning">Turned off on this server (feature flag).</p>}
                  <InstructionsBlock p={p} />
                </Card>
              ))}
            </div>
            {d.open_conflicts > 0 && <Notice tone="warning">{d.open_conflicts} source conflict{d.open_conflicts === 1 ? '' : 's'} need review — see below.</Notice>}
            {d.connections.filter((c) => c.mode === 'mcp').length === 0 ? (
              <Card><Empty title="No live connections yet">Add a Monarch or Snowball connection above. You can add several of each (for example one per family member's login).</Empty></Card>
            ) : d.connections.filter((c) => c.mode === 'mcp').map((c) => (
              <ConnectionCard key={c.id} c={c} provider={d.providers.find((p) => p.provider === c.provider)} expanded={open === c.id} onToggle={() => setOpen(open === c.id ? null : c.id)} />
            ))}
            <Conflicts />
          </>
        )}
      </Query>
      {adding && <AddDialog p={adding} onClose={() => setAdding(null)} onCreated={(id) => { setAdding(null); setOpen(id); }} />}
    </div>
  );
}

function InstructionsBlock({ p }: { p: ProviderInfo }) {
  return (
    <details className="mt-3 text-sm">
      <summary className="cursor-pointer font-medium">What to do in {p.label}</summary>
      <ol className="mt-2 list-decimal space-y-1.5 pl-5 text-ink-soft">{p.instructions.steps.map((s, i) => <li key={i}>{s}</li>)}</ol>
      {p.redirect_uri && <p className="mt-2 text-xs text-muted">Redirect URL registered automatically: <code>{p.redirect_uri}</code></p>}
      <ul className="mt-2 list-disc space-y-1 pl-5 text-xs text-muted">{p.instructions.notes.map((n, i) => <li key={i}>{n}</li>)}</ul>
      <div className="mt-3 flex flex-wrap gap-2">{p.instructions.links.map((l) => <a key={l.url} className="btn-secondary btn-sm" href={l.url} target="_blank" rel="noreferrer noopener">{l.label} ↗</a>)}</div>
    </details>
  );
}

function AddDialog({ p, onClose, onCreated }: { p: ProviderInfo; onClose: () => void; onCreated: (id: string) => void }) {
  const { scopes } = useScopes('ledger');
  const [v, setV] = useState({ label: p.label, scope_id: '' });
  const create = useCommand<Record<string, string>, Connection>('integrations');
  const submit = (e: FormEvent) => {
    e.preventDefault();
    create.mutate({ provider: p.provider, label: v.label, scope_id: v.scope_id || scopes[0]?.id || '' }, { onSuccess: (c) => onCreated(c.id) });
  };
  return (
    <Dialog open onClose={onClose} title={`Add ${p.label} connection`}>
      <form onSubmit={submit} className="space-y-4">
        <label className="field"><span>Name</span><input value={v.label} onChange={(e) => setV({ ...v, label: e.target.value })} placeholder={`${p.label} (Rob)`} required maxLength={120} /></label>
        <label className="field"><span>Privacy scope for imported accounts</span>
          <select value={v.scope_id} onChange={(e) => setV({ ...v, scope_id: e.target.value })}>{scopes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
        <p className="text-xs text-muted">New accounts from this connection are created in this scope. You can add more than one {p.label} connection, for example one per login.</p>
        <FormError error={create.error} />
        <div className="flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={onClose}>Cancel</button><button className="btn-primary" disabled={create.isPending}>Create</button></div>
      </form>
    </Dialog>
  );
}

const STATUS_HINT: Record<string, string> = {
  needs_auth: 'Authorize to continue', needs_test: 'Run Test to discover tools', needs_review: 'Preview and approve the mapping', active: 'Syncing', error: 'Last attempt failed', disconnected: 'Disconnected',
};

function ConnectionCard({ c: summary, provider, expanded, onToggle }: { c: Connection; provider?: ProviderInfo; expanded: boolean; onToggle: () => void }) {
  const full = useWsQuery<Connection>(['integration', summary.id], `integrations/${summary.id}`, { enabled: expanded });
  const c = full.data ?? summary;
  return (
    <Card title={<span className="flex items-center gap-2"><SourceIcon provider={c.provider} size={22} />{c.label}</span>}
      subtitle={<span>{providerName(c.provider)} · <StatusBadge status={c.status === 'needs_auth' || c.status === 'needs_test' || c.status === 'needs_review' ? 'needs_review' : c.status} label={humanize(c.status)} /> {STATUS_HINT[c.status] ?? ''}
        {c.last_success_at ? ` · last sync ${relative(c.last_success_at)}` : ''} · {c.linked_accounts} linked account{c.linked_accounts === 1 ? '' : 's'}</span>}
      actions={<button className="btn-secondary btn-sm" onClick={onToggle} aria-expanded={expanded}>{expanded ? 'Close' : 'Manage'}</button>}>
      {c.last_error && <Notice tone="danger">{c.last_error}</Notice>}
      {expanded && <ConnectionBody c={c} provider={provider} />}
    </Card>
  );
}

function ConnectionBody({ c, provider }: { c: Connection; provider?: ProviderInfo }) {
  const { id: wsId } = useWorkspace();
  const qc = useQueryClient();
  const [job, setJob] = useState<{ id: string; label: string } | null>(null);
  const [err, setErr] = useState<unknown>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<{ server: { name: string | null; version: string | null }; tools: { name: string }[]; missing: string[] } | null>(null);
  const refresh = () => qc.invalidateQueries({ queryKey: [wsId] });
  const run = async (name: string, fn: () => Promise<unknown>) => {
    setBusy(name); setErr(null);
    try { await fn(); await refresh(); } catch (e) { setErr(e); } finally { setBusy(null); }
  };
  const post = <T,>(path: string, body: unknown = {}) => api<{ data: T }>(ws(wsId, `integrations/${c.id}/${path}`), { body }).then((r) => r.data);
  const startJob = (path: 'preview' | 'sync', label: string) => run(path, async () => {
    const r = await post<{ job: { id: string } }>(path);
    setJob({ id: r.job.id, label });
  });
  const previewCurrent = c.last_preview && JSON.stringify(c.last_preview.tool_map) === JSON.stringify(c.tool_map);
  return (
    <div className="space-y-6">
      <section aria-labelledby={`auth-${c.id}`} className="space-y-3">
        <h3 id={`auth-${c.id}`} className="text-sm font-semibold">1 · Authorize</h3>
        {c.authorized ? (
          <p className="text-sm">Authorized {c.credential_hint ? <code className="text-xs">{c.credential_hint}</code> : null} {c.credential_set_at ? `on ${date(c.credential_set_at)}` : ''}
            {c.token_expires_at ? ` · access token renews automatically` : ''}</p>
        ) : <p className="text-sm text-muted">Not authorized yet.</p>}
        {c.auth_kind === 'api_key' ? <KeyForm c={c} onSaved={refresh} />
          : <button className="btn-primary btn-sm" disabled={busy !== null} onClick={() => run('oauth', async () => {
              const r = await post<{ authorize_url: string }>('oauth/start');
              window.location.assign(r.authorize_url);
            })}>{c.authorized ? 'Reconnect with Monarch' : 'Connect with Monarch'}</button>}
        {provider && <InstructionsBlock p={provider} />}
      </section>

      {c.authorized && (
        <section className="space-y-3">
          <h3 className="text-sm font-semibold">2 · Test &amp; map tools</h3>
          <div className="flex flex-wrap gap-2">
            <button className="btn-secondary btn-sm" disabled={busy !== null} onClick={() => run('test', async () => setTestResult(await post('test')))}>{busy === 'test' ? 'Testing…' : 'Test connection'}</button>
          </div>
          {testResult && <Notice tone={testResult.missing.length ? 'warning' : 'success'}>Connected to {testResult.server.name ?? 'server'} {testResult.server.version ?? ''} · {testResult.tools.length} tools.
            {testResult.missing.length ? ` No tool found for: ${testResult.missing.join(', ')}.` : ''}</Notice>}
          {c.tool_catalog?.length ? <ToolMapForm c={c} onSaved={refresh} /> : null}
        </section>
      )}

      {c.authorized && c.tool_map?.accounts && (
        <section className="space-y-3">
          <h3 className="text-sm font-semibold">3 · Preview &amp; approve</h3>
          <p className="text-xs text-muted">A preview reads from {providerName(c.provider)} and shows what would change. Nothing is written until you approve.</p>
          <div className="flex flex-wrap gap-2">
            <button className="btn-secondary btn-sm" disabled={busy !== null} onClick={() => startJob('preview', 'Preview')}>Run preview</button>
            {previewCurrent && !c.approved_at && <button className="btn-primary btn-sm" disabled={busy !== null || !c.last_preview?.accounts.length} onClick={() => run('approve', () => post('approve'))}>Approve &amp; enable sync</button>}
          </div>
          {c.last_preview && <PreviewTable p={c.last_preview} stale={!previewCurrent} />}
        </section>
      )}

      {c.approved_at && (
        <section className="space-y-3">
          <h3 className="text-sm font-semibold">4 · Sync</h3>
          <p className="text-sm">Approved {date(c.approved_at)} · runs every {c.sync_interval_hours} h{c.last_success_at ? ` · last success ${relative(c.last_success_at)}` : ''}.</p>
          <div className="flex flex-wrap gap-2">
            <button className="btn-primary btn-sm" disabled={busy !== null} onClick={() => startJob('sync', 'Sync')}>Sync now</button>
          </div>
          {c.last_sync_stats && <SyncStats s={c.last_sync_stats} />}
          <OptionsForm c={c} onSaved={refresh} />
        </section>
      )}

      {job && <JobStatus jobId={job.id} label={job.label} onDone={() => { void refresh(); }} />}
      <FormError error={err} />
      {c.authorized && (
        <div className="flex justify-end border-t border-line pt-4">
          <button className="btn-danger btn-sm" disabled={busy !== null} onClick={() => { if (confirm(`Disconnect ${c.label}? Credentials are deleted${c.auth_kind === 'oauth' ? ' and the token is revoked at Monarch' : ''}. Imported data stays.`)) void run('disconnect', () => post('disconnect')); }}>Disconnect</button>
        </div>
      )}
    </div>
  );
}

function KeyForm({ c, onSaved }: { c: Connection; onSaved: () => void }) {
  const [key, setKey] = useState('');
  const [url, setUrl] = useState('');
  const save = useCommand<{ key: string; server_url?: string }>(`integrations/${c.id}/key`);
  return (
    <form className="grid grid-cols-1 gap-3 sm:grid-cols-[2fr_2fr_auto] sm:items-end" onSubmit={(e) => { e.preventDefault(); save.mutate({ key, server_url: url || undefined }, { onSuccess: () => { setKey(''); onSaved(); } }); }}>
      <label className="field"><span>{c.authorized ? 'Replace MCP key' : 'MCP key'}</span>
        <input type="password" value={key} onChange={(e) => setKey(e.target.value)} autoComplete="off" spellCheck={false} required minLength={8} placeholder="Paste the key shown once on Snowball's MCP tab" /></label>
      <label className="field"><span>Server address (optional)</span><input value={url} onChange={(e) => setUrl(e.target.value)} placeholder={c.server_url ?? ''} inputMode="url" /></label>
      <button className="btn-primary" disabled={save.isPending || key.length < 8}>Save key</button>
      <div className="sm:col-span-3"><FormError error={save.error} /></div>
    </form>
  );
}

function ToolMapForm({ c, onSaved }: { c: Connection; onSaved: () => void }) {
  const [map, setMap] = useState<Record<string, string>>(() => ({ accounts: c.tool_map?.accounts ?? '', holdings: c.tool_map?.holdings ?? '', transactions: c.tool_map?.transactions ?? '' }));
  const save = useCommand<Record<string, string | null>>(`integrations/${c.id}/tools`, 'PUT');
  const tools = c.tool_catalog ?? [];
  const dirty = ['accounts', 'holdings', 'transactions'].some((k) => (map[k] || null) !== (c.tool_map?.[k] ?? null));
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        {(['accounts', 'holdings', 'transactions'] as const).map((role) => (
          <label key={role} className="field"><span>{humanize(role)} tool</span>
            <select value={map[role]} onChange={(e) => setMap({ ...map, [role]: e.target.value })}>
              <option value="">— none —</option>
              {tools.map((t) => <option key={t.name} value={t.name}>{t.name}</option>)}
            </select></label>
        ))}
      </div>
      {dirty && <button className="btn-secondary btn-sm" disabled={save.isPending} onClick={() => save.mutate({ accounts: map.accounts || null, holdings: map.holdings || null, transactions: map.transactions || null }, { onSuccess: onSaved })}>Save mapping</button>}
      <FormError error={save.error} />
      <details className="text-xs text-muted"><summary className="cursor-pointer">All {tools.length} tools</summary>
        <ul className="mt-1 space-y-0.5">{tools.map((t) => <li key={t.name}><code>{t.name}</code>{t.description ? ` — ${t.description}` : ''}</li>)}</ul></details>
    </div>
  );
}

const ACTION: Record<PlanRow['action'], string> = { new: 'New account', link: 'Link to existing', linked: 'Already linked', possible_duplicate: 'Possible duplicate' };

function PreviewTable({ p, stale }: { p: NonNullable<Connection['last_preview']>; stale: boolean }) {
  const { path } = useWorkspace();
  return (
    <div className="space-y-2">
      {stale && <Notice tone="warning">The tool mapping changed since this preview. Run it again before approving.</Notice>}
      <p className="text-xs text-muted">Fetched {relative(p.fetched_at)} · {p.totals.accounts} accounts · {p.totals.holdings} holdings · {p.totals.transactions} transactions · {p.totals.possible_duplicate} possible duplicates</p>
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>Source account</th><th>Institution</th><th className="text-right">Balance</th><th className="text-right">Holdings</th><th className="text-right">Txns</th><th>Result</th><th>Writes</th></tr></thead>
          <tbody>{p.accounts.map((r) => (
            <tr key={r.external_id}>
              <td>{r.name}{r.mask ? <span className="text-xs text-muted"> ••{r.mask}</span> : null}<span className="block text-xs text-muted">{humanize(r.kind)}</span></td>
              <td>{r.institution ?? '—'}</td>
              <td className="text-right"><Money value={r.balance} /></td>
              <td className="num text-right">{r.holdings}</td><td className="num text-right">{r.transactions}</td>
              <td>
                <StatusBadge status={r.action === 'possible_duplicate' ? 'needs_review' : r.action === 'new' ? 'draft' : 'active'} label={ACTION[r.action]} />
                {r.candidate && r.action !== 'new' && <span className="block text-xs text-muted">{r.action === 'possible_duplicate' ? 'Similar to ' : '→ '}
                  <Link className="link" to={path(`accounts/${r.candidate.id}`)}>{r.candidate.name}</Link> ({r.candidate.reasons.map(humanize).join(', ')})</span>}
                {r.action === 'linked' && r.account_id && <Link className="link block text-xs" to={path(`accounts/${r.account_id}`)}>Open account</Link>}
              </td>
              <td className="text-xs">{r.authoritative ? 'Balances, holdings, transactions' : 'Compared only (another source is authoritative)'}</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
      {p.issues.length > 0 && <ul className="list-disc space-y-0.5 pl-5 text-xs text-warning">{p.issues.map((i) => <li key={i}>{i}</li>)}</ul>}
    </div>
  );
}

function SyncStats({ s }: { s: Record<string, unknown> }) {
  const { path } = useWorkspace();
  const keys = ['accounts_created', 'accounts_linked', 'balances', 'holdings', 'observed_only', 'possible_duplicates', 'transactions_staged', 'open_conflicts'];
  return (
    <div className="space-y-1 text-sm">
      <p className="text-xs text-muted">{keys.filter((k) => Number(s[k] ?? 0) > 0).map((k) => `${humanize(k)}: ${num(Number(s[k]), 0)}`).join(' · ') || 'No changes in the last sync.'}</p>
      {typeof s.import_batch_id === 'string' && <Link className="link text-xs" to={path(`imports/${s.import_batch_id}`)}>Review staged transactions →</Link>}
      {Array.isArray(s.issues) && s.issues.length > 0 && <ul className="list-disc pl-5 text-xs text-warning">{(s.issues as string[]).map((i) => <li key={i}>{i}</li>)}</ul>}
    </div>
  );
}

function OptionsForm({ c, onSaved }: { c: Connection; onSaved: () => void }) {
  const { id: wsId } = useWorkspace();
  const [v, setV] = useState({ import_transactions: c.options.import_transactions ?? true, auto_commit_transactions: c.options.auto_commit_transactions ?? false,
    transactions_days: c.options.transactions_days ?? 90, sync_interval_hours: c.sync_interval_hours });
  const [err, setErr] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);
  return (
    <details className="text-sm">
      <summary className="cursor-pointer font-medium">Sync options</summary>
      <form className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2" onSubmit={async (e) => {
        e.preventDefault(); setSaving(true); setErr(null);
        try { await api(ws(wsId, `integrations/${c.id}`), { method: 'PATCH', body: v, ifMatch: c.edit_version }); onSaved(); } catch (x) { setErr(x); } finally { setSaving(false); }
      }}>
        <label className="field flex-row items-center gap-3"><input type="checkbox" className="h-5 w-5 min-h-0" checked={v.import_transactions} onChange={(e) => setV({ ...v, import_transactions: e.target.checked })} /><span>Import transactions</span></label>
        <label className="field flex-row items-center gap-3"><input type="checkbox" className="h-5 w-5 min-h-0" checked={v.auto_commit_transactions} onChange={(e) => setV({ ...v, auto_commit_transactions: e.target.checked })} /><span>Auto-commit when nothing needs review</span></label>
        <label className="field"><span>Transaction look-back (days)</span><input type="number" min={1} max={730} value={v.transactions_days} onChange={(e) => setV({ ...v, transactions_days: Number(e.target.value) })} /></label>
        <label className="field"><span>Sync every (hours)</span><input type="number" min={1} max={720} value={v.sync_interval_hours} onChange={(e) => setV({ ...v, sync_interval_hours: Number(e.target.value) })} /></label>
        <div className="sm:col-span-2 flex items-center justify-end gap-3"><FormError error={err} /><button className="btn-secondary btn-sm" disabled={saving}>Save options</button></div>
      </form>
    </details>
  );
}
