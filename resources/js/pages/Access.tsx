import { useState } from 'react';
import { useCommand, useResourceList, useWsQuery } from '../api/hooks';
import type { AccessOverview } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, Dialog, FormError, Notice, PageHeader, Query, StatusBadge, Tabs } from '../components/ui';
import { date, humanize } from '../lib/format';

const ROLES = ['administrator', 'principal', 'member', 'advisor', 'dependent'];
const ACTIONS = ['read', 'write', 'approve', 'export', 'share'];

function InviteDialog({ onClose }: { onClose: () => void }) {
  const persons = useResourceList('persons', { limit: 200 });
  const [v, setV] = useState({ email: '', role: 'member', label: '', person_id: '', expires_at: '' });
  const invite = useCommand<Record<string, unknown>, { invite_url: string; note: string }>('access/invitations');
  return (
    <Dialog open onClose={onClose} title="Invite someone">
      {invite.data ? (
        <div className="space-y-3 text-sm">
          <Notice tone="success">{invite.data.note}</Notice>
          <p className="break-all rounded bg-sunken p-2 font-mono text-xs">{invite.data.invite_url}</p>
          <div className="flex justify-end gap-2"><button className="btn-secondary" onClick={() => void navigator.clipboard?.writeText(invite.data!.invite_url)}>Copy</button><button className="btn-primary" onClick={onClose}>Done</button></div>
        </div>
      ) : (
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); invite.mutate(Object.fromEntries(Object.entries(v).filter(([, x]) => x !== ''))); }}>
          <label className="field"><span>Email (must match their McNutt Cloud login)</span><input type="email" value={v.email} onChange={(e) => setV({ ...v, email: e.target.value })} required /></label>
          <div className="grid grid-cols-2 gap-4">
            <label className="field"><span>Role</span><select value={v.role} onChange={(e) => setV({ ...v, role: e.target.value })}>{ROLES.map((r) => <option key={r} value={r}>{humanize(r)}</option>)}</select></label>
            <label className="field"><span>Display label</span><input value={v.label} onChange={(e) => setV({ ...v, label: e.target.value })} /></label>
            <label className="field"><span>Person record</span><select value={v.person_id} onChange={(e) => setV({ ...v, person_id: e.target.value })}><option value="">— None —</option>{(persons.data?.data ?? []).map((p) => <option key={p.id} value={p.id}>{p._title}</option>)}</select></label>
            <label className="field"><span>Access ends {v.role === 'advisor' ? '*' : ''}</span><input type="date" value={v.expires_at} onChange={(e) => setV({ ...v, expires_at: e.target.value })} required={v.role === 'advisor'} /></label>
          </div>
          <Notice>Roles do not grant data access. After they join, grant access to specific scopes and areas.</Notice>
          <FormError error={invite.error} />
          <div className="flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={onClose}>Cancel</button><button className="btn-primary">Create invitation</button></div>
        </form>
      )}
    </Dialog>
  );
}

function GrantDialog({ data, onClose }: { data: AccessOverview; onClose: () => void }) {
  const { me } = useWorkspace();
  const [v, setV] = useState<{ membership_id: string; scope_id: string; domains: string[]; actions: string[]; years: string; purpose: string; expires_at: string }>({ membership_id: '', scope_id: '', domains: [], actions: ['read'], years: '', purpose: '', expires_at: '' });
  const grant = useCommand<Record<string, unknown>, { pending_approval: boolean }>('access/grants');
  const toggle = (k: 'domains' | 'actions', x: string) => setV({ ...v, [k]: v[k].includes(x) ? v[k].filter((y) => y !== x) : [...v[k], x] });
  return (
    <Dialog open onClose={onClose} title="Grant access" wide>
      {grant.data ? (
        <div className="space-y-4"><Notice tone="success">{grant.data.pending_approval ? 'Grant created and waiting for the scope steward to approve.' : 'Access granted.'}</Notice><div className="flex justify-end"><button className="btn-primary" onClick={onClose}>Done</button></div></div>
      ) : (
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); grant.mutate({ ...v, years: v.years ? v.years.split(',').map((y) => Number(y.trim())) : undefined, expires_at: v.expires_at || undefined, purpose: v.purpose || undefined }); }}>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label className="field"><span>Person</span><select value={v.membership_id} onChange={(e) => setV({ ...v, membership_id: e.target.value })} required><option value="">— Select —</option>{data.members.filter((m) => m.status === 'active').map((m) => <option key={m.id} value={m.id}>{m.name ?? m.label ?? m.email} ({m.role})</option>)}</select></label>
            <label className="field"><span>Scope</span><select value={v.scope_id} onChange={(e) => setV({ ...v, scope_id: e.target.value })} required><option value="">— Select —</option>{data.scopes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
          </div>
          <fieldset><legend className="text-sm font-medium">Areas</legend>
            <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">{Object.entries(me.domains).map(([k, l]) => <label key={k} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={v.domains.includes(k)} onChange={() => toggle('domains', k)} />{l}</label>)}</div>
          </fieldset>
          <fieldset><legend className="text-sm font-medium">Actions</legend>
            <div className="mt-2 flex flex-wrap gap-4">{ACTIONS.map((a) => <label key={a} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={v.actions.includes(a)} onChange={() => toggle('actions', a)} />{humanize(a)}</label>)}</div>
          </fieldset>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <label className="field"><span>Tax years (optional, comma-separated)</span><input value={v.years} onChange={(e) => setV({ ...v, years: e.target.value })} placeholder="2024, 2025" /></label>
            <label className="field"><span>Purpose</span><input value={v.purpose} onChange={(e) => setV({ ...v, purpose: e.target.value })} placeholder="2025 return preparation" /></label>
            <label className="field"><span>Expires</span><input type="date" value={v.expires_at} onChange={(e) => setV({ ...v, expires_at: e.target.value })} /></label>
          </div>
          <FormError error={grant.error} />
          <div className="flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={onClose}>Cancel</button><button className="btn-primary" disabled={!v.domains.length || !v.actions.length}>Grant</button></div>
        </form>
      )}
    </Dialog>
  );
}

export default function Access() {
  const { me: who } = useWorkspace();
  const q = useWsQuery<AccessOverview>(['access'], 'access');
  const [tab, setTab] = useState('members');
  const [invite, setInvite] = useState(false);
  const [grant, setGrant] = useState(false);
  const setStatus = useCommand<{ id: string; status: string }>((b) => `access/members/${b.id}/status`);
  const setRole = useCommand<{ id: string; role: string }>((b) => `access/members/${b.id}/role`);
  const approve = useCommand<{ id: string }>((b) => `access/grants/${b.id}/approve`);
  const revoke = useCommand<{ id: string }>((b) => `access/grants/${b.id}/revoke`);
  const newScope = useCommand<{ name: string; kind: string }>('access/scopes');
  const addSteward = useCommand<{ scope: string; membership_id: string }>((b) => `access/scopes/${b.scope}/stewards`);
  return (
    <Query q={q}>
      {(d) => {
        const name = (mid: string) => { const m = d.members.find((x) => x.id === mid); return m ? (m.name ?? m.label ?? m.email ?? mid) : mid.slice(-6); };
        const scopeName = (sid: string) => d.scopes.find((s) => s.id === sid)?.name ?? sid.slice(-6);
        return (
          <div className="space-y-6">
            <PageHeader eyebrow="Admin" title="Access" description="Privacy scopes hold records; stewards control them. Roles never grant data access on their own — every view comes from an explicit, auditable grant."
              actions={<>
                {d.me.is_administrator && <button className="btn-secondary" onClick={() => setInvite(true)}>Invite</button>}
                {(d.me.is_administrator || d.me.steward_of.length > 0) && <button className="btn-primary" onClick={() => setGrant(true)}>Grant access</button>}
              </>} />
            {!who.user.stepup_enrolled && <Notice tone="warning">Set up an authenticator under Settings → Security. It is required to change access, approve, export or share.</Notice>}
            <FormError error={setStatus.error ?? setRole.error ?? approve.error ?? revoke.error ?? newScope.error ?? addSteward.error} />
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'members', label: 'People', count: d.members.length }, { id: 'scopes', label: 'Scopes', count: d.scopes.length }, { id: 'grants', label: 'Grants', count: d.grants.length }]} />
            {tab === 'members' && (
              <Card pad={false}>
                <table className="data-table">
                  <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Access ends</th>{d.me.is_administrator && <th />}</tr></thead>
                  <tbody>{d.members.map((m) => (
                    <tr key={m.id}>
                      <td>{m.name ?? m.label}<span className="block text-xs text-muted">{m.email}</span>{m.is_recovery_admin && <span className="badge bg-pine-tint text-pine">Recovery admin</span>}</td>
                      <td>{d.me.is_administrator && m.id !== d.me.membership_id ? (
                        <select aria-label="Role" className="input min-h-9 max-w-40" value={m.role} onChange={(e) => setRole.mutate({ id: m.id, role: e.target.value })}>{ROLES.map((r) => <option key={r} value={r}>{humanize(r)}</option>)}</select>
                      ) : humanize(m.role)}</td>
                      <td><StatusBadge status={m.status} /></td>
                      <td className="num">{date(m.expires_at)}</td>
                      {d.me.is_administrator && <td>{m.id !== d.me.membership_id && (
                        <div className="flex gap-2">
                          {m.status === 'active' && <button className="btn-secondary btn-sm" onClick={() => setStatus.mutate({ id: m.id, status: 'suspended' })}>Suspend</button>}
                          {['suspended', 'pending_approval'].includes(m.status) && <button className="btn-secondary btn-sm" onClick={() => setStatus.mutate({ id: m.id, status: 'active' })}>Activate</button>}
                          {m.status !== 'removed' && <button className="btn-danger btn-sm" onClick={() => { if (confirm('Remove this person? All grants and sessions end immediately.')) setStatus.mutate({ id: m.id, status: 'removed' }); }}>Remove</button>}
                        </div>
                      )}</td>}
                    </tr>
                  ))}</tbody>
                </table>
              </Card>
            )}
            {tab === 'scopes' && (
              <div className="space-y-4">
                <div className="flex justify-end"><button className="btn-secondary btn-sm" onClick={() => { const n = prompt('Scope name'); if (!n) return; const k = prompt('Kind: joint, private, branch, entity, shared or advisor', 'private'); if (k) newScope.mutate({ name: n, kind: k }); }}>New scope</button></div>
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                  {d.scopes.map((s) => (
                    <Card key={s.id} title={s.name} subtitle={humanize(s.kind)}>
                      <p className="text-sm">Stewards: {s.stewards.map(name).join(', ') || '—'}</p>
                      <p className="mt-2 text-xs text-muted">Your access: {Object.entries(s.my_access).map(([dom, acts]) => `${who.domains[dom] ?? dom} (${acts.join(', ')})`).join('; ') || 'none'}</p>
                      {d.me.is_administrator && (
                        <select aria-label={`Add steward to ${s.name}`} className="input mt-3 max-w-64" value="" onChange={(e) => e.target.value && addSteward.mutate({ scope: s.id, membership_id: e.target.value })}>
                          <option value="">Add steward…</option>{d.members.filter((m) => m.status === 'active' && !s.stewards.includes(m.id)).map((m) => <option key={m.id} value={m.id}>{name(m.id)}</option>)}
                        </select>
                      )}
                    </Card>
                  ))}
                </div>
              </div>
            )}
            {tab === 'grants' && (
              <Card pad={false}>
                <table className="data-table">
                  <thead><tr><th>Person</th><th>Scope</th><th>Areas</th><th>Actions</th><th>Years</th><th>Expires</th><th>Status</th><th /></tr></thead>
                  <tbody>{d.grants.map((g) => (
                    <tr key={g.id}>
                      <td>{name(g.membership_id)}</td><td>{scopeName(g.scope_id)}</td>
                      <td className="text-xs">{g.domains.map((x) => who.domains[x] ?? x).join(', ')}</td><td className="text-xs">{g.actions.join(', ')}</td>
                      <td className="text-xs">{g.years?.join(', ') ?? 'All'}</td><td className="num">{date(g.expires_at)}</td>
                      <td><StatusBadge status={g.pending_approval ? 'pending' : 'active'} /></td>
                      <td><div className="flex gap-2">
                        {g.pending_approval && d.me.steward_of.includes(g.scope_id) && <button className="btn-secondary btn-sm" onClick={() => approve.mutate({ id: g.id })}>Approve</button>}
                        {(d.me.is_administrator || d.me.steward_of.includes(g.scope_id)) && <button className="btn-danger btn-sm" onClick={() => revoke.mutate({ id: g.id })}>Revoke</button>}
                      </div></td>
                    </tr>
                  ))}</tbody>
                </table>
              </Card>
            )}
            {invite && <InviteDialog onClose={() => setInvite(false)} />}
            {grant && <GrantDialog data={d} onClose={() => setGrant(false)} />}
          </div>
        );
      }}
    </Query>
  );
}
