import { useState } from 'react';
import { useCommand, useResourceList, useWsQuery } from '../api/hooks';
import type { AnyRecord, Job } from '../api/types';
import { Card, Dialog, FormError, Notice, PageHeader, Query, StatusBadge } from '../components/ui';
import { useScopes } from '../components/resource';
import { JobStatus } from '../components/security';
import { date, humanize } from '../lib/format';

const KINDS: { id: string; label: string; desc: string }[] = [
  { id: 'net_worth', label: 'Net worth statement', desc: 'Assets, liabilities and methods as of a date.' },
  { id: 'cpa_package', label: 'CPA package', desc: 'Tax forms, payments, carryforwards, rental schedules and the latest run with trace.' },
  { id: 'insurer_package', label: 'Insurer package', desc: 'Scheduled items, values and coverage — tax and identity redacted by default.' },
  { id: 'wealth_bridge', label: 'Year-end wealth bridge', desc: 'Beginning-to-ending net worth with the residual.' },
  { id: 'retirement_report', label: 'Retirement report', desc: 'Latest projections, ranges and assumptions.' },
  { id: 'beneficiary_report', label: 'Beneficiary report', desc: 'Designations by account and policy.' },
  { id: 'emergency_binder', label: 'Emergency binder', desc: 'Contacts, documents and instructions.' },
  { id: 'counsel_binder', label: 'Counsel binder', desc: 'Estate documents, roles, trusts and the latest model.' },
];
const REDACTIONS = ['account_numbers', 'identity', 'addresses', 'beneficiaries', 'tax'];

interface ExportRow { id: string; report_manifest_id: string; recipient_name: string; recipient_email: string; expires_at: string; revoked_at: string | null; max_downloads: number; download_count: number; sent_at: string | null; created_at: string }

function ShareDialog({ manifest, onClose }: { manifest: AnyRecord; onClose: () => void }) {
  const [v, setV] = useState({ recipient_name: '', recipient_email: '', expires_in_days: '7', max_downloads: '3' });
  const create = useCommand<Record<string, unknown>, { grant_id: string; link: string; verification_code: string; expires_at: string }>(`reports/${manifest.id}/exports`);
  const send = useCommand<{ grant: string; link: string }>((b) => `exports/${b.grant}/send`);
  return (
    <Dialog open onClose={onClose} title={`Share "${manifest._title}"`}>
      {create.data ? (
        <div className="space-y-4 text-sm">
          <Notice tone="success">Link created. It expires {date(create.data.expires_at)}.</Notice>
          <p>Access code (share separately, e.g. by phone): <code className="text-lg">{create.data.verification_code}</code></p>
          <p className="break-all text-xs text-muted">{create.data.link}</p>
          <FormError error={send.error} />
          <div className="flex justify-end gap-2">
            <button className="btn-secondary" onClick={() => void navigator.clipboard?.writeText(create.data!.link)}>Copy link</button>
            <button className="btn-primary" disabled={send.isSuccess} onClick={() => send.mutate({ grant: create.data!.grant_id, link: create.data!.link })}>{send.isSuccess ? 'Sent' : 'Email link'}</button>
          </div>
        </div>
      ) : (
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); create.mutate({ ...v, expires_in_days: Number(v.expires_in_days), max_downloads: Number(v.max_downloads) }); }}>
          <label className="field"><span>Recipient name</span><input value={v.recipient_name} onChange={(e) => setV({ ...v, recipient_name: e.target.value })} required /></label>
          <label className="field"><span>Recipient email</span><input type="email" value={v.recipient_email} onChange={(e) => setV({ ...v, recipient_email: e.target.value })} required /></label>
          <div className="grid grid-cols-2 gap-4">
            <label className="field"><span>Expires in (days)</span><input inputMode="numeric" value={v.expires_in_days} onChange={(e) => setV({ ...v, expires_in_days: e.target.value })} /></label>
            <label className="field"><span>Max downloads</span><input inputMode="numeric" value={v.max_downloads} onChange={(e) => setV({ ...v, max_downloads: e.target.value })} /></label>
          </div>
          <FormError error={create.error} />
          <div className="flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={onClose}>Cancel</button><button className="btn-primary">Create link</button></div>
        </form>
      )}
    </Dialog>
  );
}

export default function Reports() {
  const { scopes } = useScopes('reports');
  const [kind, setKind] = useState('net_worth');
  const [scope, setScope] = useState('');
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));
  const [year, setYear] = useState(String(new Date().getFullYear() - 1));
  const [red, setRed] = useState<string[]>(['account_numbers']);
  const [job, setJob] = useState<string | null>(null);
  const [share, setShare] = useState<AnyRecord | null>(null);
  const create = useCommand<Record<string, unknown>, { manifest_id: string; job: Job }>('reports');
  const dl = useCommand<{ id: string }, { url: string }>((b) => `reports/${b.id}/download`);
  const manifests = useResourceList('report_manifests', { limit: 50 });
  const exports = useWsQuery<ExportRow[]>(['exports'], 'exports');
  const revoke = useCommand<{ id: string }>((b) => `exports/${b.id}/revoke`);
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Family" title="Reports" description="Point-in-time packages (PDF summary, CSV tables and a manifest) stored in the vault. Share with advisors through expiring, code-protected links." />
      <Card title="Create a report">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {KINDS.map((k) => (
            <button key={k.id} type="button" onClick={() => { setKind(k.id); if (k.id === 'insurer_package') setRed(['tax', 'beneficiaries', 'identity', 'account_numbers']); }}
              className={`rounded-xl border p-3 text-left text-sm ${kind === k.id ? 'border-pine bg-pine-tint' : 'border-line hover:bg-sunken'}`} aria-pressed={kind === k.id}>
              <span className="font-medium">{k.label}</span><span className="mt-1 block text-xs text-muted">{k.desc}</span>
            </button>
          ))}
        </div>
        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
          <label className="field"><span>Scope (where it is stored)</span><select value={scope} onChange={(e) => setScope(e.target.value)}><option value="">— Select —</option>{scopes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
          <label className="field"><span>As of</span><input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} /></label>
          {['cpa_package', 'wealth_bridge'].includes(kind) && <label className="field"><span>Tax year</span><input inputMode="numeric" value={year} onChange={(e) => setYear(e.target.value)} /></label>}
        </div>
        <fieldset className="mt-4"><legend className="text-sm font-medium">Redact</legend>
          <div className="mt-2 flex flex-wrap gap-4">{REDACTIONS.map((r) => <label key={r} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={red.includes(r)} onChange={(e) => setRed((x) => (e.target.checked ? [...x, r] : x.filter((y) => y !== r)))} />{humanize(r)}</label>)}</div>
        </fieldset>
        <div className="mt-4 flex items-center gap-3">
          <button className="btn-primary" disabled={!scope || create.isPending} onClick={() => create.mutate({ kind, scope_id: scope, as_of: asOf, year: Number(year), redactions: red }, { onSuccess: (r) => setJob(r.job.id) })}>Build report</button>
          <JobStatus jobId={job} label="Report" />
        </div>
        <FormError error={create.error} />
      </Card>
      <Card title="Reports" pad={false}>
        <Query q={manifests}>
          {(p) => p.data.length === 0 ? <p className="p-5 text-sm text-muted">No reports yet.</p> : (
            <table className="data-table">
              <thead><tr><th>Title</th><th>As of</th><th>Status</th><th>Created</th><th /></tr></thead>
              <tbody>{p.data.map((m) => (
                <tr key={m.id}>
                  <td>{m._title}</td><td className="num">{date(m.as_of as string)}</td><td><StatusBadge status={String(m.status)} /></td><td className="num">{date(m.created_at as string)}</td>
                  <td>{m.status === 'ready' && <div className="flex gap-2">
                    <button className="btn-secondary btn-sm" onClick={() => dl.mutate({ id: m.id }, { onSuccess: (r) => { window.location.href = r.url; } })}>Download</button>
                    <button className="btn-secondary btn-sm" onClick={() => setShare(m)}>Share</button>
                  </div>}</td>
                </tr>
              ))}</tbody>
            </table>
          )}
        </Query>
        <div className="px-5 pb-4"><FormError error={dl.error} /></div>
      </Card>
      <Card title="Shared links" pad={false}>
        <Query q={exports}>
          {(rows) => rows.length === 0 ? <p className="p-5 text-sm text-muted">Nothing shared.</p> : (
            <table className="data-table">
              <thead><tr><th>Recipient</th><th>Expires</th><th className="text-right">Downloads</th><th>Status</th><th /></tr></thead>
              <tbody>{rows.map((x) => (
                <tr key={x.id}>
                  <td>{x.recipient_name} <span className="text-xs text-muted">{x.recipient_email}</span></td><td className="num">{date(x.expires_at)}</td><td className="num text-right">{x.download_count} / {x.max_downloads}</td>
                  <td><StatusBadge status={x.revoked_at ? 'revoked' : new Date(x.expires_at) < new Date() ? 'expired' : 'active'} /></td>
                  <td>{!x.revoked_at && <button className="btn-danger btn-sm" onClick={() => revoke.mutate({ id: x.id })}>Revoke</button>}</td>
                </tr>
              ))}</tbody>
            </table>
          )}
        </Query>
      </Card>
      {share && <ShareDialog manifest={share} onClose={() => setShare(null)} />}
    </div>
  );
}
