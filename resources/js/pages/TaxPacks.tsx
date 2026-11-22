import { useState } from 'react';
import { useCommand, useWsQuery } from '../api/hooks';
import { Card, Dialog, FormError, Notice, PageHeader, Query, StatusBadge } from '../components/ui';
import { date } from '../lib/format';
import { useWorkspace } from '../app/workspace';

interface Pack { id: string; jurisdiction: string; tax_year: number; version: string; legislation_status: string; status: string; content_hash: string; reviewer_name: string | null; approved_at: string | null; approval_note: string | null; summary: Record<string, unknown> | null }

export default function TaxPacks() {
  const q = useWsQuery<Pack[]>(['packs'], 'tax/packs');
  const { role } = useWorkspace();
  const [open, setOpen] = useState<Pack | null>(null);
  const [name, setName] = useState('');
  const [note, setNote] = useState('');
  const approve = useCommand<{ id: string; reviewer_name: string; note: string }>((b) => `tax/packs/${b.id}/approve`);
  const isAdmin = role === 'administrator';
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Tax" title="Rule packs" description="Versioned, hashed parameter sets for each jurisdiction and year. A run can only be 'calculated' when every pack it uses has a named professional approval and enacted law." />
      <Notice>Packs ship as drafts. Before approving, verify the parity fixtures and form-line values against official IRS and NJ Division of Taxation instructions. Approval is audited and requires a recent identity confirmation.</Notice>
      <Query q={q}>
        {(packs) => (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {packs.map((p) => (
              <Card key={p.id} title={`${p.jurisdiction} ${p.tax_year}`} subtitle={`Version ${p.version} · ${p.legislation_status}`} actions={<StatusBadge status={p.status} />}>
                <p className="text-xs text-muted">Hash <code>{p.content_hash.slice(0, 16)}</code></p>
                {p.summary && (
                  <ul className="mt-2 space-y-1 text-sm">
                    {Object.entries(p.summary).slice(0, 8).map(([k, v]) => <li key={k}><span className="text-muted">{k}:</span> {typeof v === 'object' ? JSON.stringify(v) : String(v)}</li>)}
                  </ul>
                )}
                {p.reviewer_name && <p className="mt-3 text-sm">Approved by <strong>{p.reviewer_name}</strong> on {date(p.approved_at)}{p.approval_note ? ` — ${p.approval_note}` : ''}</p>}
                {isAdmin && p.status === 'draft' && <button className="btn-secondary btn-sm mt-4" onClick={() => setOpen(p)}>Approve…</button>}
              </Card>
            ))}
          </div>
        )}
      </Query>
      <Dialog open={!!open} onClose={() => setOpen(null)} title={`Approve ${open?.jurisdiction} ${open?.tax_year} v${open?.version}`}>
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); if (open) approve.mutate({ id: open.id, reviewer_name: name, note }, { onSuccess: () => setOpen(null) }); }}>
          <label className="field"><span>Reviewer name and credential</span><input value={name} onChange={(e) => setName(e.target.value)} required minLength={3} /></label>
          <label className="field"><span>What was verified</span><textarea rows={4} value={note} onChange={(e) => setNote(e.target.value)} required minLength={10} placeholder="Brackets, standard deduction, credits and phase-outs checked against Rev. Proc. …; parity fixtures pass." /></label>
          <FormError error={approve.error} />
          <div className="flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={() => setOpen(null)}>Cancel</button><button className="btn-primary">Approve pack</button></div>
        </form>
      </Dialog>
    </div>
  );
}
