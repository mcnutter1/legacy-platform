import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { api, ApiError, ws } from '../api/client';
import { useCommand, useResourceList, useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { useScopes } from '../components/resource';
import { JobStatus } from '../components/security';
import { providerName, SourceIcon } from '../components/sources';
import { Conflicts } from '../components/sourcesPanel';
import { Card, Dialog, Empty, FormError, KeyValue, Money, Notice, PageHeader, Query, StatusBadge } from '../components/ui';
import { date, humanize, num, relative } from '../lib/format';

interface Instructions { summary: string; steps: string[]; links: { label: string; url: string }[]; notes: string[] }
interface ProviderInfo { provider: string; label: string; auth_kind: 'oauth' | 'api_key'; enabled: boolean; default_url: string; instructions: Instructions; redirect_uri: string | null;
  transport: 'mcp' | 'rest'; target: 'property' | 'entity' | null }
interface YearSummary { year: number; summary: { gross_revenue: string | null; taxes_collected: string | null; expenses: string | null; net_operating_income: string | null; bookings: number;
  days: Record<string, number> | null }; stays: number; new_stays: number; pending_skipped: number; expenses: number; undetermined: number }
interface RestPreview {
  ok: true; kind: 'property_manager' | 'invoices'; fetched_at: string; issues: string[];
  remote_property?: Record<string, string>; property?: { id: string; name: string }; years?: YearSummary[];
  business?: { name: string; legal_name: string; entity_type: string; reporting_basis: string; books_closed_through: string | null };
  entity?: { id: string; name: string; kind: string; tax_classification: string | null } | null;
  accounts_map?: MappedAccount[]; pnl?: { income: string | null; cogs: string | null; expense: string | null; net: string | null } | null; year?: number;
}
interface MappedAccount { id: string; code: string; name: string; type: string; subtype: string | null; line: string }
interface PlanRow { external_id: string; name: string; institution: string | null; kind: string; mask: string | null; balance: string | null; currency: string; holdings: number; transactions: number;
  action: 'new' | 'link' | 'linked' | 'possible_duplicate'; account_id: string | null; candidate: { id: string; name: string; score: number; reasons: string[] } | null; authoritative: boolean }
interface McpPreview { accounts: PlanRow[]; issues: string[]; tool_map: Record<string, string | null>; fetched_at: string; totals: Record<string, number>; ok?: undefined }
interface Connection {
  id: string; scope_id: string; provider: string; label: string; mode: string; status: string; auth_kind: string | null; server_url: string | null; authorized: boolean;
  credential_hint: string | null; credential_set_at: string | null; token_expires_at: string | null; approved_at: string | null; last_sync_at: string | null; last_success_at: string | null;
  last_error: string | null; last_job_id: string | null; sync_interval_hours: number; options: { import_transactions?: boolean; auto_commit_transactions?: boolean; transactions_days?: number };
  tool_map: Record<string, string | null> | null; last_sync_stats: Record<string, unknown> | null; linked_accounts: number; edit_version: number;
  target: { property_id?: string; entity_id?: string | null } | null; business: { name: string } | null;
  tool_catalog?: { name: string; description: string | null }[] | null;
  last_preview?: (McpPreview | RestPreview) | null;
  accounts?: MappedAccount[];
}
const isRestPreview = (p: Connection['last_preview']): p is RestPreview => !!p && p.ok === true;
interface Overview { providers: ProviderInfo[]; connections: Connection[]; open_conflicts: number }

export default function Integrations() {
  const q = useWsQuery<Overview>(['integrations'], 'integrations');
  const [params] = useSearchParams();
  const [adding, setAdding] = useState<ProviderInfo | null>(null);
  const [open, setOpen] = useState<string | null>(params.get('connected') ?? params.get('open'));
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Integrations"
        description="Live, read-only connections: Monarch Money and Snowball Analytics over their official MCP servers, the beach-house property-management system and McNutt Finance side-business books over their data APIs. Every synced record shows where it came from; accounts reported by more than one source are de-duplicated and disagreements are flagged for review." />
      {params.get('connected') && <Notice tone="success">Authorization complete. Run Test, then Preview, then approve the mapping.</Notice>}
      <Query q={q}>
        {(d) => (
          <>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              {d.providers.map((p) => (
                <Card key={p.provider} title={<span className="flex items-center gap-2"><SourceIcon provider={p.provider} size={24} />{p.label}</span>}
                  subtitle={p.auth_kind === 'oauth' ? 'OAuth · read-only scope' : p.transport === 'rest' ? `API key · read-only · feeds ${p.target === 'property' ? 'a property' : 'a business'}` : 'Personal MCP key · read-only'}
                  actions={<button className="btn-primary btn-sm" disabled={!p.enabled} onClick={() => setAdding(p)}>Add connection</button>}>
                  <p className="text-sm text-ink-soft">{p.instructions.summary}</p>
                  {!p.enabled && <p className="mt-2 text-xs text-warning">Turned off on this server (feature flag).</p>}
                  <InstructionsBlock p={p} />
                </Card>
              ))}
            </div>
            {d.open_conflicts > 0 && <Notice tone="warning">{d.open_conflicts} source conflict{d.open_conflicts === 1 ? '' : 's'} need review — see below.</Notice>}
            {d.connections.filter((c) => c.mode === 'mcp' || c.mode === 'api').length === 0 ? (
              <Card><Empty title="No live connections yet">Add a connection above. You can add several of each (for example one per family member's login, one per rental or one per business).</Empty></Card>
            ) : d.connections.filter((c) => c.mode === 'mcp' || c.mode === 'api').map((c) => (
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
  const [v, setV] = useState({ label: p.label, scope_id: '', property_id: '', entity_id: '' });
  const props = useResourceList('properties', { limit: 200 }, p.target === 'property');
  const entities = useResourceList('legal_entities', { limit: 200 }, p.target === 'entity');
  const propOptions = (props.data?.data ?? []).filter((r) => ['rental_str', 'rental_ltr', 'mixed', 'second_home'].includes(String(r.kind)));
  const create = useCommand<Record<string, string>, Connection>('integrations');
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const body: Record<string, string> = { provider: p.provider, label: v.label, scope_id: v.scope_id || scopes[0]?.id || '' };
    if (p.target === 'property') body.property_id = v.property_id || String(propOptions[0]?.id ?? '');
    if (p.target === 'entity' && v.entity_id) body.entity_id = v.entity_id;
    create.mutate(body, { onSuccess: (c) => onCreated(c.id) });
  };
  return (
    <Dialog open onClose={onClose} title={`Add ${p.label} connection`}>
      <form onSubmit={submit} className="space-y-4">
        <label className="field"><span>Name</span><input value={v.label} onChange={(e) => setV({ ...v, label: e.target.value })} placeholder={`${p.label} (Rob)`} required maxLength={120} /></label>
        {p.target === 'property' && (
          <label className="field"><span>Property this system manages</span>
            <select value={v.property_id} onChange={(e) => setV({ ...v, property_id: e.target.value })} required>
              {propOptions.length === 0 && <option value="">No rental or second-home properties yet</option>}
              {propOptions.map((r) => <option key={String(r.id)} value={String(r.id)}>{String(r.name)}</option>)}
            </select></label>
        )}
        {p.target === 'entity' && (
          <label className="field"><span>Business these books belong to</span>
            <select value={v.entity_id} onChange={(e) => setV({ ...v, entity_id: e.target.value })}>
              <option value="">Create it from the Finance business profile</option>
              {(entities.data?.data ?? []).map((r) => <option key={String(r.id)} value={String(r.id)}>{String(r.name)} ({humanize(String(r.kind))})</option>)}
            </select></label>
        )}
        <label className="field"><span>Privacy scope for the connection{p.target === 'entity' ? ' and a new business entity' : p.target ? '' : ' and imported accounts'}</span>
          <select value={v.scope_id} onChange={(e) => setV({ ...v, scope_id: e.target.value })}>{scopes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
        <p className="text-xs text-muted">{p.target === 'property' ? 'Stays and expenses are written to the chosen property and keep its privacy scope.'
          : p.target === 'entity' ? 'Use one connection (and one Finance key) per business.'
          : `New accounts from this connection are created in this scope. You can add more than one ${p.label} connection, for example one per login.`}</p>
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
  const { id: wsId, path } = useWorkspace();
  const qc = useQueryClient();
  const [job, setJob] = useState<{ id: string; label: string } | null>(null);
  const [err, setErr] = useState<unknown>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<{ server: { name: string | null; version: string | null }; tools: { name: string }[]; missing: string[];
    property?: Record<string, string>; business?: { name: string; entity_type: string; reporting_basis: string }; scopes?: string[] } | null>(null);
  const [loopback, setLoopback] = useState<OAuthStart | null>(null);
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
  const rest = provider?.transport === 'rest' || c.mode === 'api';
  const previewCurrent = rest ? isRestPreview(c.last_preview) : c.last_preview && JSON.stringify((c.last_preview as { tool_map?: unknown }).tool_map) === JSON.stringify(c.tool_map);
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
              const r = await post<OAuthStart>('oauth/start');
              if (r.mode === 'web') window.location.assign(r.authorize_url);
              else setLoopback(r);
            })}>{c.authorized ? 'Reconnect with Monarch' : 'Connect with Monarch'}</button>}
        {loopback && <LoopbackFinish c={c} start={loopback} onDone={() => { setLoopback(null); void refresh(); }} />}
        {provider && <InstructionsBlock p={provider} />}
      </section>

      {c.authorized && (
        <section className="space-y-3">
          <h3 className="text-sm font-semibold">{rest ? '2 · Test' : <>2 · Test &amp; map tools</>}</h3>
          <div className="flex flex-wrap gap-2">
            <button className="btn-secondary btn-sm" disabled={busy !== null} onClick={() => run('test', async () => setTestResult(await post('test')))}>{busy === 'test' ? 'Testing…' : 'Test connection'}</button>
          </div>
          {testResult && rest && <Notice tone={testResult.missing.length ? 'warning' : 'success'}>Connected to {testResult.server.name ?? 'server'} ({testResult.server.version})
            {testResult.property ? ` · property “${testResult.property.name}”${testResult.property.city ? `, ${testResult.property.city}` : ''}` : ''}
            {testResult.business ? ` · ${humanize(testResult.business.entity_type)}, ${testResult.business.reporting_basis} basis · key scopes: ${(testResult.scopes ?? []).join(', ') || 'none'}` : ''}
            {testResult.missing.length ? `. The key is missing: ${testResult.missing.join(', ')}.` : '.'}</Notice>}
          {testResult && !rest && <Notice tone={testResult.missing.length ? 'warning' : 'success'}>Connected to {testResult.server.name ?? 'server'} {testResult.server.version ?? ''} · {testResult.tools.length} tools.
            {testResult.missing.length ? ` No tool found for: ${testResult.missing.join(', ')}.` : ''}</Notice>}
          {!rest && c.tool_catalog?.length ? <ToolMapForm c={c} onSaved={refresh} /> : null}
        </section>
      )}

      {c.authorized && (rest || c.tool_map?.accounts) && (
        <section className="space-y-3">
          <h3 className="text-sm font-semibold">3 · Preview &amp; approve</h3>
          <p className="text-xs text-muted">A preview reads from {providerName(c.provider)} and shows what would change. Nothing is written until you approve.</p>
          <div className="flex flex-wrap gap-2">
            <button className="btn-secondary btn-sm" disabled={busy !== null} onClick={() => startJob('preview', 'Preview')}>Run preview</button>
            {previewCurrent && !c.approved_at && <button className="btn-primary btn-sm" disabled={busy !== null || (!rest && !(c.last_preview as McpPreview | null)?.accounts?.length)} onClick={() => run('approve', () => post('approve'))}>Approve &amp; enable sync</button>}
          </div>
          {isRestPreview(c.last_preview) ? <RestPreviewView p={c.last_preview} />
            : c.last_preview && <PreviewTable p={c.last_preview as McpPreview} stale={!previewCurrent} />}
          {c.provider === 'invoices' && c.accounts && c.accounts.length > 0 && <AccountMapForm c={c} onSaved={refresh} />}
        </section>
      )}

      {c.approved_at && (
        <section className="space-y-3">
          <h3 className="text-sm font-semibold">4 · Sync</h3>
          <p className="text-sm">Approved {date(c.approved_at)} · runs every {c.sync_interval_hours} h{c.last_success_at ? ` · last success ${relative(c.last_success_at)}` : ''}.</p>
          <div className="flex flex-wrap gap-2">
            <button className="btn-primary btn-sm" disabled={busy !== null} onClick={() => startJob('sync', 'Sync')}>Sync now</button>
            {rest && <Link className="btn-secondary btn-sm" to={path('business-income')}>Open Businesses &amp; rentals</Link>}
          </div>
          {c.last_sync_stats && (rest ? <RestSyncStats s={c.last_sync_stats} /> : <SyncStats s={c.last_sync_stats} />)}
          {!rest && <OptionsForm c={c} onSaved={refresh} />}
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

interface OAuthStart { authorize_url: string; mode: 'web' | 'loopback'; redirect_uri: string; web_redirect: string; notice: string | null }

/** Provider hasn't approved our web callback yet: authorize in a new tab, then paste the 127.0.0.1 address it lands on. */
function LoopbackFinish({ c, start, onDone }: { c: Connection; start: OAuthStart; onDone: () => void }) {
  const { id: wsId } = useWorkspace();
  const [pasted, setPasted] = useState('');
  const [err, setErr] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const finish = async () => {
    setErr(null);
    let u: URL;
    try { u = new URL(pasted.trim()); } catch { setErr(new Error('Paste the full address from the tab, starting with http://127.0.0.1')); return; }
    const code = u.searchParams.get('code');
    const state = u.searchParams.get('state');
    if (u.searchParams.get('error')) { setErr(new Error(`Monarch returned: ${u.searchParams.get('error_description') ?? u.searchParams.get('error')}`)); return; }
    if (!code || !state) { setErr(new Error('That address has no authorization code. Approve access in Monarch first, then copy the address of the page it opens.')); return; }
    setBusy(true);
    try { await api(ws(wsId, `integrations/${c.id}/oauth/complete`), { body: { code, state } }); onDone(); } catch (x) { setErr(x); } finally { setBusy(false); }
  };
  return (
    <div className="space-y-3 rounded-lg border border-line p-4 text-sm">
      <Notice tone="info">Monarch has not approved this site’s return address yet, so authorization finishes with a copy-and-paste step (the standard approach for apps without an approved callback).</Notice>
      <ol className="list-decimal space-y-1.5 pl-5">
        <li><a className="btn-primary btn-sm" href={start.authorize_url} target="_blank" rel="noreferrer noopener">Open Monarch to approve ↗</a> — sign in and leave only <strong>Read</strong> checked.</li>
        <li>After you approve, that tab opens a page at <code>127.0.0.1</code> that will not load (“site can’t be reached”). That is expected.</li>
        <li>Copy the full address from that tab’s address bar and paste it here (it expires in 10 minutes).</li>
      </ol>
      <div className="flex flex-wrap items-end gap-2">
        <label className="field min-w-72 flex-1"><span>Address from the Monarch tab</span>
          <input value={pasted} onChange={(e) => setPasted(e.target.value)} placeholder="http://127.0.0.1:33418/callback?code=…&state=…" spellCheck={false} autoComplete="off" /></label>
        <button className="btn-primary" disabled={busy || !pasted.trim()} onClick={() => void finish()}>Finish connecting</button>
      </div>
      <FormError error={err instanceof ApiError ? err : null} />
      {err && !(err instanceof ApiError) ? <p className="text-sm text-negative" role="alert">{(err as Error).message}</p> : null}
      <details className="text-xs text-muted"><summary className="cursor-pointer">Skip this step in future</summary>
        <p className="mt-1">Request approval of <code>{start.web_redirect}</code> in Monarch under <a className="link" href="https://app.monarch.com/settings/integrations" target="_blank" rel="noreferrer noopener">Settings → MCP Connector</a>. Once approved, Connect returns here automatically.</p>
        {start.notice && <p className="mt-1">Monarch said: {start.notice}</p>}
      </details>
    </div>
  );
}

function KeyForm({ c, onSaved }: { c: Connection; onSaved: () => void }) {
  const [key, setKey] = useState('');
  const [url, setUrl] = useState('');
  const save = useCommand<{ key: string; server_url?: string }>(`integrations/${c.id}/key`);
  return (
    <form className="grid grid-cols-1 gap-3 sm:grid-cols-[2fr_2fr_auto] sm:items-end" onSubmit={(e) => { e.preventDefault(); save.mutate({ key, server_url: url || undefined }, { onSuccess: () => { setKey(''); onSaved(); } }); }}>
      <label className="field"><span>{c.authorized ? 'Replace key' : c.mode === 'api' ? 'API key' : 'MCP key'}</span>
        <input type="password" value={key} onChange={(e) => setKey(e.target.value)} autoComplete="off" spellCheck={false} required minLength={8}
          placeholder={c.provider === 'property_manager' ? 'fo_… from PMS Settings → Family Office API' : c.provider === 'invoices' ? 'fin_… from Finance → Settings → Data API access' : "Paste the key shown once on Snowball's MCP tab"} /></label>
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

function PreviewTable({ p, stale }: { p: McpPreview; stale: boolean }) {
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

const DAY_LABELS: Record<string, string> = { rental: 'Rental', personal: 'Personal', owner_maintenance: 'Maintenance', blocked: 'Blocked', available_unrented: 'Available, unrented' };

function RestPreviewView({ p }: { p: RestPreview }) {
  return (
    <div className="space-y-3">
      <p className="text-xs text-muted">Fetched {relative(p.fetched_at)}</p>
      {p.kind === 'property_manager' && p.years && (
        <>
          <p className="text-sm">PMS property <strong>{p.remote_property?.name ?? '—'}</strong> → Legacy property <strong>{p.property?.name}</strong></p>
          <div className="table-wrap">
            <table className="data-table">
              <caption className="sr-only">PMS profit and loss and import counts by year</caption>
              <thead><tr><th>Year</th><th className="text-right">Gross revenue</th><th className="text-right">Expenses</th><th className="text-right">Net operating income</th>
                <th className="text-right">Stays (new)</th><th className="text-right">Expenses (undetermined)</th><th>Days</th></tr></thead>
              <tbody>{p.years.map((y) => (
                <tr key={y.year}>
                  <td>{y.year}</td>
                  <td className="text-right"><Money value={y.summary.gross_revenue} cents /></td>
                  <td className="text-right"><Money value={y.summary.expenses} cents /></td>
                  <td className="text-right"><Money value={y.summary.net_operating_income} cents /></td>
                  <td className="num text-right">{y.stays} ({y.new_stays}){y.pending_skipped ? <span className="block text-xs text-muted">{y.pending_skipped} unpaid skipped</span> : null}</td>
                  <td className="num text-right">{y.expenses} ({y.undetermined})</td>
                  <td className="text-xs">{y.summary.days ? Object.entries(y.summary.days).filter(([, n]) => n > 0).map(([k, n]) => `${DAY_LABELS[k] ?? humanize(k)} ${n}`).join(' · ') : '—'}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        </>
      )}
      {p.kind === 'invoices' && p.business && (
        <>
          <p className="text-sm"><strong>{p.business.legal_name || p.business.name}</strong> · {humanize(p.business.entity_type)} · {p.business.reporting_basis} basis
            {p.business.books_closed_through ? ` · books closed through ${date(p.business.books_closed_through)}` : ''}</p>
          <p className="text-sm">{p.entity ? <>Feeds the entity <strong>{p.entity.name}</strong> ({humanize(p.entity.kind)}{p.entity.tax_classification ? `, ${humanize(p.entity.tax_classification)}` : ''}).</>
            : 'On approval Legacy creates the business entity from this profile.'}</p>
          {p.pnl && <KeyValue items={[[`${p.year} cash income`, <Money key="i" value={p.pnl.income} cents />], ['Cost of goods sold', <Money key="c" value={p.pnl.cogs} cents />],
            ['Expenses', <Money key="e" value={p.pnl.expense} cents />], ['Net', <Money key="n" value={p.pnl.net} cents />]]} />}
        </>
      )}
      {p.issues.length > 0 && <ul className="list-disc space-y-0.5 pl-5 text-xs text-warning">{p.issues.map((i) => <li key={i}>{i}</li>)}</ul>}
    </div>
  );
}

const SCHEDULE_C_LINES: Record<string, string> = {
  gross: 'Gross receipts (line 1)', returns: 'Returns & allowances (2)', cogs: 'Cost of goods sold (4)', advertising: 'Advertising (8)', car_truck: 'Car & truck (9)',
  commissions: 'Commissions & fees (10)', contract_labor: 'Contract labor (11)', depreciation: 'Depreciation (13)', insurance: 'Insurance (15)', interest: 'Interest (16)',
  legal_professional: 'Legal & professional (17)', office: 'Office expense (18)', rent: 'Rent or lease (20)', repairs: 'Repairs & maintenance (21)', supplies: 'Supplies (22)',
  taxes_licenses: 'Taxes & licenses (23)', travel: 'Travel (24a)', meals: 'Meals, 50% (24b)', utilities: 'Utilities (25)', wages: 'Wages (26)', other: 'Other expenses (27a)',
  exclude: 'Not on Schedule C (draws, income tax)',
};

function AccountMapForm({ c, onSaved }: { c: Connection; onSaved: () => void }) {
  const { id: wsId } = useWorkspace();
  const accounts = c.accounts ?? [];
  const [map, setMap] = useState<Record<string, string>>(() => Object.fromEntries(accounts.map((a) => [a.id, a.line])));
  const [err, setErr] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);
  const changed = Object.fromEntries(accounts.filter((a) => map[a.id] && map[a.id] !== a.line).map((a) => [a.id, map[a.id]]));
  return (
    <details className="text-sm" open={!c.approved_at}>
      <summary className="cursor-pointer font-medium">Schedule C mapping ({accounts.length} income &amp; expense accounts)</summary>
      <p className="mt-2 text-xs text-muted">Each Finance account feeds one Schedule C line. Suggestions come from the account name; change any that are wrong. Applies to every year on the next tax run.</p>
      <div className="table-wrap mt-2">
        <table className="data-table">
          <thead><tr><th>Account</th><th>Type</th><th>Schedule C line</th></tr></thead>
          <tbody>{accounts.map((a) => (
            <tr key={a.id}>
              <td>{a.code ? <span className="text-xs text-muted">{a.code} </span> : null}{a.name}</td>
              <td className="text-xs">{humanize(a.subtype === 'cogs' ? 'cogs' : a.type)}</td>
              <td><label className="sr-only" htmlFor={`line-${c.id}-${a.id}`}>Schedule C line for {a.name}</label>
                <select id={`line-${c.id}-${a.id}`} value={map[a.id] ?? a.line} onChange={(e) => setMap({ ...map, [a.id]: e.target.value })}>
                  {Object.entries(SCHEDULE_C_LINES).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                </select></td>
            </tr>
          ))}</tbody>
        </table>
      </div>
      <div className="mt-2 flex items-center justify-end gap-3">
        <FormError error={err} />
        <button className="btn-secondary btn-sm" disabled={saving || Object.keys(changed).length === 0} onClick={async () => {
          setSaving(true); setErr(null);
          try { await api(ws(wsId, `integrations/${c.id}/account-map`), { method: 'PUT', body: { map: changed }, ifMatch: c.edit_version }); onSaved(); } catch (x) { setErr(x); } finally { setSaving(false); }
        }}>Save mapping</button>
      </div>
    </details>
  );
}

interface Reconciliation { year: number; pms_gross_revenue?: string | null; legacy_gross_revenue?: string; revenue_difference?: string | null; pms_expenses?: string | null; legacy_expenses?: string;
  finance?: { net: string | null } | null; legacy?: { net: string }; net_difference?: string | null }

function RestSyncStats({ s }: { s: Record<string, unknown> }) {
  const keys = ['stays_created', 'stays_updated', 'stays_archived', 'pending_skipped', 'expenses_created', 'expenses_updated', 'expenses_archived', 'undetermined', 'linked_to_monarch',
    'lines_created', 'unknown_accounts'];
  const rec = (Array.isArray(s.reconciliation) ? s.reconciliation : []) as Reconciliation[];
  const est = s.tax_estimate as { year: number; net_business_income: string | null; business_tax: string | null } | null | undefined;
  return (
    <div className="space-y-2 text-sm">
      <p className="text-xs text-muted">{keys.filter((k) => Number(s[k] ?? 0) > 0).map((k) => `${humanize(k)}: ${num(Number(s[k]), 0)}`).join(' · ') || 'No changes in the last sync.'}</p>
      {rec.length > 0 && (
        <ul className="space-y-0.5 text-xs">
          {rec.map((r) => {
            const diff = r.revenue_difference ?? r.net_difference;
            const ok = diff === '0.00' || diff === '-0.00';
            return <li key={r.year} className={ok ? 'text-positive' : diff == null ? 'text-muted' : 'text-warning'}>
              {r.year}: {r.legacy_gross_revenue !== undefined ? <>revenue in Legacy <Money value={r.legacy_gross_revenue} cents /> vs PMS <Money value={r.pms_gross_revenue ?? null} cents /></>
                : <>net in Legacy <Money value={r.legacy?.net ?? null} cents /> vs Finance <Money value={r.finance?.net ?? null} cents /></>}
              {ok ? ' · matches' : diff != null ? ` · differs by ${diff}` : ''}</li>;
          })}
        </ul>
      )}
      {est && <p className="text-xs text-muted">Finance tax estimate for {est.year} (provisional, comparison only): business net <Money value={est.net_business_income} cents />, added tax <Money value={est.business_tax} cents />.</p>}
    </div>
  );
}
