import { useEffect, useState } from 'react';
import { useCommand, useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { Card, FormError, KeyValue, Notice, PageHeader, Query, StatusBadge, Tabs } from '../components/ui';
import { AuthenticatorSetup } from '../components/security';
import { date, humanize, relative } from '../lib/format';

interface Prefs { email_enabled: boolean; email_digest: string; quiet_start?: string | null; quiet_end?: string | null; timezone: string; muted_kinds?: string[] | null }
interface Consent { id: string; membership_id: string; kind: string; granted_at: string }
interface Session { id: string; current: boolean; user_agent: string; last_activity: string }
interface App { client_id: string; name: string | null; scopes: string[]; connected_at: string; last_used_at: string | null }

function Security() {
  const { me } = useWorkspace();
  const [ok, setOk] = useState(false);
  const sessions = useWsQuery<Session[]>(['sessions'], 'access/sessions');
  const revoke = useCommand('access/sessions/revoke-others');
  return (
    <div className="space-y-6">
      <Card title="Authenticator (step-up)" subtitle="Required for access changes, approvals, exports and sharing.">
        {ok && <Notice tone="success">Authenticator confirmed.</Notice>}
        <p className="mb-4 text-sm">Status: <StatusBadge status={me.user.stepup_enrolled ? 'active' : 'pending'} label={me.user.stepup_enrolled ? 'Enrolled' : 'Not set up'} /></p>
        <AuthenticatorSetup replacing={me.user.stepup_enrolled} onDone={() => setOk(true)} />
      </Card>
      <Card title="Sessions" actions={<button className="btn-secondary btn-sm" onClick={() => revoke.mutate({})}>Sign out other sessions</button>}>
        <Query q={sessions}>
          {(rows) => <ul className="space-y-2 text-sm">{rows.map((s) => <li key={s.id} className="flex justify-between gap-3"><span className="truncate">{s.user_agent || 'Unknown device'}{s.current && <span className="badge ml-2 bg-pine-tint text-pine">This device</span>}</span><span className="text-muted">{relative(s.last_activity)}</span></li>)}</ul>}
        </Query>
      </Card>
    </div>
  );
}

function Notifications() {
  const q = useWsQuery<Prefs>(['prefs'], 'notification-preferences');
  const save = useCommand<Partial<Prefs>>('notification-preferences', 'PUT');
  const [p, setP] = useState<Prefs | null>(null);
  useEffect(() => { if (q.data) setP(q.data); }, [q.data]);
  if (!p) return <Query q={q}>{() => null}</Query>;
  return (
    <Card title="Notifications" subtitle="Email is sent through McNutt Cloud notifications and never includes amounts or account details.">
      <form className="grid grid-cols-1 gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); save.mutate({ email_enabled: p.email_enabled, email_digest: p.email_digest, quiet_start: p.quiet_start || null, quiet_end: p.quiet_end || null, timezone: p.timezone }); }}>
        <label className="field flex-row items-center gap-3"><input type="checkbox" className="h-5 w-5 min-h-0" checked={p.email_enabled} onChange={(e) => setP({ ...p, email_enabled: e.target.checked })} /><span>Email me</span></label>
        <label className="field"><span>Frequency</span><select value={p.email_digest} onChange={(e) => setP({ ...p, email_digest: e.target.value })}>{['immediate', 'daily', 'weekly', 'off'].map((x) => <option key={x} value={x}>{humanize(x)}</option>)}</select></label>
        <label className="field"><span>Quiet hours start</span><input type="time" value={p.quiet_start ?? ''} onChange={(e) => setP({ ...p, quiet_start: e.target.value })} /></label>
        <label className="field"><span>Quiet hours end</span><input type="time" value={p.quiet_end ?? ''} onChange={(e) => setP({ ...p, quiet_end: e.target.value })} /></label>
        <label className="field"><span>Time zone</span><input value={p.timezone} onChange={(e) => setP({ ...p, timezone: e.target.value })} /></label>
        <div className="flex items-end justify-end"><button className="btn-primary" disabled={save.isPending}>{save.isSuccess ? 'Saved' : 'Save'}</button></div>
        <div className="sm:col-span-2"><FormError error={save.error} /></div>
      </form>
    </Card>
  );
}

function Privacy() {
  const { me, role } = useWorkspace();
  const consents = useWsQuery<Consent[]>(['consents'], 'access/consents');
  const apps = useWsQuery<App[]>(['apps'], 'access/connected-apps');
  const grant = useCommand<{ kind: string }>('access/consents');
  const revokeConsent = useCommand<{ id: string }>((b) => `access/consents/${b.id}/revoke`);
  const revokeApp = useCommand<{ id: string }>((b) => `access/connected-apps/${encodeURIComponent(b.id)}/revoke`);
  const mine = me.workspaces.find((w) => w.status === 'active')?.membership_id;
  const KINDS: { kind: string; label: string; desc: string; admin?: boolean }[] = [
    { kind: 'combined_planning', label: 'Combined planning', desc: 'Allow joint plans that include my data with other participants.' },
    { kind: 'ai_processing', label: 'AI document extraction (workspace)', desc: 'Send document images to OpenAI for structured extraction (no training, zero retention where available).', admin: true },
    { kind: 'emergency_release', label: 'Emergency release', desc: 'Allow emergency binders that include my records to be released by break-glass.' },
  ];
  return (
    <div className="space-y-6">
      <Card title="Consents">
        <Query q={consents}>
          {(rows) => (
            <ul className="space-y-4">
              {KINDS.filter((k) => !k.admin || role === 'administrator').map((k) => {
                const c = rows.find((r) => r.kind === k.kind && (k.admin || r.membership_id === mine));
                return (
                  <li key={k.kind} className="flex flex-wrap items-start justify-between gap-3">
                    <div><p className="font-medium">{k.label}</p><p className="text-sm text-muted">{k.desc}</p>{c && <p className="text-xs text-muted">Granted {date(c.granted_at)}</p>}</div>
                    {c ? <button className="btn-secondary btn-sm" onClick={() => revokeConsent.mutate({ id: c.id })}>Withdraw</button> : <button className="btn-primary btn-sm" onClick={() => grant.mutate({ kind: k.kind })}>Consent</button>}
                  </li>
                );
              })}
            </ul>
          )}
        </Query>
        <FormError error={grant.error ?? revokeConsent.error} />
      </Card>
      <Card title="Connected apps" subtitle="ChatGPT and other MCP clients you authorized. Revoking ends access immediately.">
        <Query q={apps}>
          {(rows) => rows.length === 0 ? <p className="text-sm text-muted">No connected apps.</p> : (
            <ul className="space-y-3">{rows.map((a) => (
              <li key={a.client_id} className="flex flex-wrap items-center justify-between gap-3">
                <div><p className="font-medium">{a.name ?? a.client_id}</p><p className="text-xs text-muted">{a.scopes.join(', ')} · connected {date(a.connected_at)} · last used {relative(a.last_used_at)}</p></div>
                <button className="btn-danger btn-sm" onClick={() => revokeApp.mutate({ id: a.client_id })}>Disconnect</button>
              </li>
            ))}</ul>
          )}
        </Query>
        <p className="mt-4 text-xs text-muted">To connect ChatGPT, add a connector with the URL <code>{window.location.origin}/mcp</code> and sign in when prompted.</p>
      </Card>
    </div>
  );
}

interface Ops { jobs: Record<string, number>; oldest_queued_job: string | null; outbox_pending: number; deliveries: Record<string, number>; delivery_attention: { id: string; status: string; attempts: number; last_error: string | null; updated_at: string }[]; notifications_health: Record<string, unknown>; auth_health: Record<string, unknown>; backup: Record<string, unknown> | null; release: string }

function Operations() {
  const q = useWsQuery<Ops>(['operations'], 'operations', { refetchInterval: 30_000 });
  const resend = useCommand<{ id: string }>((b) => `deliveries/${b.id}/resend`);
  return (
    <Query q={q}>
      {(o) => (
        <div className="space-y-6">
          <Card title="Health">
            <KeyValue items={[['Release', o.release], ['Jobs', Object.entries(o.jobs).map(([k, v]) => `${k}: ${v}`).join(', ') || 'none'], ['Oldest queued job', relative(o.oldest_queued_job)],
              ['Outbox pending', o.outbox_pending], ['Deliveries', Object.entries(o.deliveries).map(([k, v]) => `${k}: ${v}`).join(', ') || 'none'],
              ['Notifications service', JSON.stringify(o.notifications_health)], ['Sign-in service', JSON.stringify(o.auth_health)], ['Last backup', o.backup ? `${String(o.backup.status)} · ${String(o.backup.finished_at ?? '')}` : 'No status file']]} />
          </Card>
          {o.delivery_attention.length > 0 && (
            <Card title="Deliveries needing attention" pad={false}>
              <table className="data-table"><thead><tr><th>Status</th><th>Attempts</th><th>Error</th><th>Updated</th><th /></tr></thead>
                <tbody>{o.delivery_attention.map((d) => <tr key={d.id}><td><StatusBadge status={d.status} /></td><td className="num">{d.attempts}</td><td className="text-xs">{d.last_error}</td><td className="num">{relative(d.updated_at)}</td><td><button className="btn-secondary btn-sm" onClick={() => resend.mutate({ id: d.id })}>Resend</button></td></tr>)}</tbody></table>
            </Card>
          )}
        </div>
      )}
    </Query>
  );
}

export default function Settings() {
  const { role } = useWorkspace();
  const [tab, setTab] = useState('security');
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Admin" title="Settings" />
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'security', label: 'Security' }, { id: 'notifications', label: 'Notifications' }, { id: 'privacy', label: 'Privacy & apps' }, ...(role === 'administrator' ? [{ id: 'ops', label: 'Operations' }] : [])]} />
      {tab === 'security' && <Security />}
      {tab === 'notifications' && <Notifications />}
      {tab === 'privacy' && <Privacy />}
      {tab === 'ops' && <Operations />}
    </div>
  );
}
