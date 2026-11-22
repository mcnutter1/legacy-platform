import { useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, ws } from '../api/client';
import { useWsQuery, useResourceList } from '../api/hooks';
import type { AnyRecord } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, Dialog, FormError, Money, Notice, Query, StatusBadge } from './ui';
import { ResourceSection } from './resource';
import { date, humanize, pct } from '../lib/format';

interface Owner { owner_type: 'person' | 'entity'; owner_id: string; name?: string; fraction: string; title_form?: string | null; effective_from?: string | null }
interface Title { subject_type: string; subject_id: string; title_form: string | null; owners: Owner[]; complete: boolean; forms: Record<string, string> }

const EQUAL = ['jtwros', 'tenancy_by_entirety', 'community'];
const HELP: Record<string, string> = {
  sole: 'One owner holds 100%.',
  jtwros: 'Two or more owners with equal shares; on a death the survivors take the whole automatically.',
  tenancy_by_entirety: 'Married couples (or civil-union partners) holding real property; equal shares with survivorship and creditor protection.',
  tenants_in_common: 'Two or more owners with any shares; each share passes under that owner\u2019s will or estate.',
  community: 'Spouses holding community property (relevant if acquired in a community-property state).',
  trust: 'Held by one or more trusts (the trustee holds legal title).',
  entity: 'Held by an LLC, partnership or other entity.',
};

/** Who holds title to a property, asset, account or debt \u2014 one owner or several. */
export function TitlePanel({ type, id }: { type: 'property' | 'asset' | 'account' | 'liability'; id: string }) {
  const q = useWsQuery<Title>(['title', type, id], `title/${type}/${id}`);
  const [edit, setEdit] = useState(false);
  return (
    <div className="space-y-6">
      <Query q={q}>
        {(t) => (
          <Card title="Owners" subtitle={t.title_form ? t.forms[t.title_form] : 'No owners recorded'}
            actions={<button className="btn-primary btn-sm" onClick={() => setEdit(true)}>{t.owners.length ? 'Change owners' : 'Add owners'}</button>}>
            {t.owners.length === 0 ? <p className="text-sm text-muted">Record who holds title. Joint ownership (two or more people, trusts or entities) is supported.</p> : (
              <ul className="flex flex-wrap gap-2">
                {t.owners.map((o) => (
                  <li key={o.owner_type + o.owner_id} className="rounded-lg border border-line px-3 py-2 text-sm">
                    <span className="font-medium">{o.name}</span> <span className="text-muted">{pct(o.fraction, o.fraction.match(/^0\.(\d{2})0+$/) ? 0 : 2)}</span>
                    {o.owner_type === 'entity' && <span className="ml-1 text-xs text-muted">(entity)</span>}
                  </li>
                ))}
              </ul>
            )}
            {t.owners.length > 0 && !t.complete && <Notice tone="warning">Shares do not add up to 100%. Change owners to fix.</Notice>}
            {t.owners[0]?.effective_from && <p className="mt-3 text-xs text-muted">Since {date(t.owners[0].effective_from)}</p>}
          </Card>
        )}
      </Query>
      {edit && q.data && <TitleDialog t={q.data} type={type} id={id} onClose={() => setEdit(false)} />}
      <ResourceSection type="ownership_interests" filter={{ subject_type: type, subject_id: id }} title="Ownership history" compact
        description="Every change is effective-dated; earlier owners stay in the history." />
    </div>
  );
}

function TitleDialog({ t, type, id, onClose }: { t: Title; type: string; id: string; onClose: () => void }) {
  const { id: wsId } = useWorkspace();
  const qc = useQueryClient();
  const persons = useResourceList('persons', { limit: 200 });
  const entities = useResourceList('legal_entities', { limit: 200 });
  const [form, setForm] = useState(t.title_form ?? (t.owners.length > 1 ? 'jtwros' : 'sole'));
  const [from, setFrom] = useState(new Date().toISOString().slice(0, 10));
  const [rows, setRows] = useState<{ owner_type: 'person' | 'entity'; owner_id: string; percent: string }[]>(
    t.owners.length ? t.owners.map((o) => ({ owner_type: o.owner_type, owner_id: o.owner_id, percent: (Number(o.fraction) * 100).toFixed(2).replace(/\.00$/, '') }))
      : [{ owner_type: 'person', owner_id: '', percent: '100' }]);
  const [err, setErr] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);
  const equal = EQUAL.includes(form);
  const total = rows.reduce((s, r) => s + (Number(r.percent) || 0), 0);
  const set = (i: number, patch: Partial<(typeof rows)[number]>) => setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const options = (t: 'person' | 'entity') => ((t === 'person' ? persons : entities).data?.data ?? []) as AnyRecord[];
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setSaving(true); setErr(null);
    try {
      await api(ws(wsId, `title/${type}/${id}`), { method: 'PUT', body: { title_form: form, effective_from: from,
        owners: rows.map((r) => ({ owner_type: r.owner_type, owner_id: r.owner_id, fraction: equal ? undefined : String((Number(r.percent) || 0) / 100) })) } });
      await qc.invalidateQueries({ queryKey: [wsId] });
      onClose();
    } catch (x) { setErr(x); } finally { setSaving(false); }
  };
  return (
    <Dialog open onClose={onClose} title="Owners" wide>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label className="field"><span>How title is held</span>
            <select value={form} onChange={(e) => setForm(e.target.value)}>
              {Object.entries(t.forms).filter(([k]) => k !== 'tenancy_by_entirety' || type === 'property').map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select></label>
          <label className="field"><span>Effective from</span><input type="date" value={from} onChange={(e) => setFrom(e.target.value)} required /></label>
        </div>
        <p className="text-xs text-muted">{HELP[form]}</p>
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">Owners</legend>
          {rows.map((r, i) => (
            <div key={i} className="grid grid-cols-[8rem_1fr_6rem_auto] items-end gap-2">
              <label className="field"><span className="sr-only">Owner type</span>
                <select value={r.owner_type} onChange={(e) => set(i, { owner_type: e.target.value as 'person' | 'entity', owner_id: '' })}>
                  <option value="person">Person</option><option value="entity">Trust / entity</option></select></label>
              <label className="field"><span className="sr-only">Owner</span>
                <select value={r.owner_id} onChange={(e) => set(i, { owner_id: e.target.value })} required>
                  <option value="">— Select —</option>
                  {options(r.owner_type).map((o) => <option key={o.id} value={o.id}>{o._title}</option>)}
                </select></label>
              <label className="field"><span className="sr-only">Share %</span>
                <input inputMode="decimal" value={equal ? (100 / rows.length).toFixed(2).replace(/\.00$/, '') : r.percent} disabled={equal}
                  onChange={(e) => set(i, { percent: e.target.value.replace(/[^\d.]/g, '') })} aria-label="Share percent" /></label>
              <button type="button" className="btn-ghost btn-sm" aria-label="Remove owner" disabled={rows.length === 1} onClick={() => setRows(rows.filter((_, j) => j !== i))}>✕</button>
            </div>
          ))}
          <div className="flex items-center justify-between">
            <button type="button" className="btn-secondary btn-sm" onClick={() => setRows([...rows, { owner_type: 'person', owner_id: '', percent: '' }])}>Add owner</button>
            {!equal && <span className={`text-sm ${Math.abs(total - 100) < 0.0001 ? 'text-muted' : 'text-negative'}`}>Total {total.toFixed(2).replace(/\.00$/, '')}%</span>}
          </div>
        </fieldset>
        <p className="text-xs text-muted">Missing someone? Add them under People &amp; entities. Tenancy by the entirety requires the spouses’ relationship to be recorded there.</p>
        <FormError error={err} />
        <div className="flex justify-end gap-2 border-t border-line pt-4">
          <button type="button" className="btn-secondary" onClick={onClose}>Cancel</button>
          <button className="btn-primary" disabled={saving}>{saving ? 'Saving\u2026' : 'Save owners'}</button>
        </div>
      </form>
    </Dialog>
  );
}

/** Assessment/levy history and NJ quarterly installments; paying posts a property-tax expense. */
export function PropertyTaxesPanel({ property }: { property: AnyRecord }) {
  const { id: wsId } = useWorkspace();
  const qc = useQueryClient();
  const inst = useResourceList('property_tax_installments', { filter: { property_id: property.id }, limit: 50 });
  const [paying, setPaying] = useState<AnyRecord | null>(null);
  const [err, setErr] = useState<unknown>(null);
  const year = new Date().getFullYear();
  const project = async () => {
    setErr(null);
    try { await api(ws(wsId, `properties/${property.id}/tax-installments`), { body: { tax_year: year } }); await qc.invalidateQueries({ queryKey: [wsId] }); } catch (x) { setErr(x); }
  };
  return (
    <div className="space-y-6">
      <Card title="Property tax installments" subtitle="New Jersey quarters are due Feb 1, May 1, Aug 1 and Nov 1 (10-day grace). Projected amounts come from last year’s levy until you enter your bill."
        actions={<button className="btn-secondary btn-sm" onClick={project}>Project {year}</button>} pad={false}>
        <Query q={inst}>
          {(p) => p.data.length === 0 ? <p className="p-5 text-sm text-muted">No installments yet. Look up public records or enter last year’s tax below, then project.</p> : (
            <table className="data-table">
              <thead><tr><th>Year</th><th>Quarter</th><th>Due</th><th className="text-right">Amount</th><th>Status</th><th>Paid</th><th /></tr></thead>
              <tbody>{p.data.map((r) => (
                <tr key={r.id}>
                  <td className="num">{String(r.tax_year)}</td><td>Q{String(r.quarter)}</td><td className="num">{date(r.due_on as string)}</td>
                  <td className="text-right"><Money value={r.amount as string} cents /></td>
                  <td><StatusBadge status={r.status === 'projected' ? 'draft' : String(r.status)} label={humanize(String(r.status))} /></td>
                  <td className="text-xs">{r.paid_on ? <>{date(r.paid_on as string)} · <Money value={r.paid_amount as string} cents /></> : '\u2014'}</td>
                  <td>{r.status !== 'paid' && <button className="btn-secondary btn-sm" onClick={() => setPaying(r)}>Record payment</button>}</td>
                </tr>
              ))}</tbody>
            </table>
          )}
        </Query>
        <div className="px-5 pb-4"><FormError error={err} /></div>
      </Card>
      <ResourceSection type="property_tax_years" filter={{ property_id: property.id }} title="Assessments & property tax by year"
        description="From the municipal tax list (MOD-IV) when you look up public records; enter or correct the amount from your tax bill. Manual entries are never overwritten." />
      {paying && <PayDialog row={paying} onClose={() => setPaying(null)} />}
    </div>
  );
}

function PayDialog({ row, onClose }: { row: AnyRecord; onClose: () => void }) {
  const { id: wsId } = useWorkspace();
  const qc = useQueryClient();
  const [v, setV] = useState({ paid_on: new Date().toISOString().slice(0, 10), amount: String(row.amount ?? '') });
  const [err, setErr] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);
  return (
    <Dialog open onClose={onClose} title={`Record payment \u2014 ${row.tax_year} Q${row.quarter}`}>
      <form className="space-y-4" onSubmit={async (e) => {
        e.preventDefault(); setSaving(true); setErr(null);
        try { await api(ws(wsId, `property-tax-installments/${row.id}/pay`), { body: v }); await qc.invalidateQueries({ queryKey: [wsId] }); onClose(); } catch (x) { setErr(x); } finally { setSaving(false); }
      }}>
        <div className="grid grid-cols-2 gap-3">
          <label className="field"><span>Paid on</span><input type="date" value={v.paid_on} onChange={(e) => setV({ ...v, paid_on: e.target.value })} required /></label>
          <label className="field"><span>Amount</span><input inputMode="decimal" value={v.amount} onChange={(e) => setV({ ...v, amount: e.target.value.replace(/[$,\s]/g, '') })} required /></label>
        </div>
        <p className="text-xs text-muted">Also records a property-tax expense for this property, which flows to the tax return (Schedule A / NJ property-tax deduction, or Schedule E for rentals).</p>
        <FormError error={err} />
        <div className="flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={onClose}>Cancel</button><button className="btn-primary" disabled={saving}>Record payment</button></div>
      </form>
    </Dialog>
  );
}

/** Deeds, mortgages and other filings with book/page, linked to the recorded copy kept in the Vault. */
export function RecordedDocumentsPanel({ property, clerkLinks }: { property: AnyRecord; clerkLinks: { label: string; url: string }[] }) {
  const { id: wsId, path } = useWorkspace();
  const qc = useQueryClient();
  const list = useResourceList('recorded_instruments', { filter: { property_id: property.id }, limit: 100 });
  const [err, setErr] = useState<unknown>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const attach = async (r: AnyRecord, file: File) => {
    setBusy(r.id); setErr(null);
    try {
      const form = new FormData();
      form.append('file', file);
      form.append('scope_id', String(property.scope_id));
      form.append('doc_type', r.kind === 'deed' ? 'deed' : 'contract');
      form.append('title', `${humanize(String(r.kind))}${r.book ? ` \u2014 book ${r.book} page ${r.page}` : ''} \u2014 ${property.name}`);
      const up = await api<{ data: { document_id: string } }>(ws(wsId, 'vault/documents'), { form });
      await api(ws(wsId, `r/recorded_instruments/${r.id}`), { method: 'PATCH', body: { document_id: up.data.document_id }, ifMatch: r.edit_version });
      await qc.invalidateQueries({ queryKey: [wsId] });
    } catch (x) { setErr(x); } finally { setBusy(null); }
  };
  return (
    <div className="space-y-4">
      <ResourceSection type="recorded_instruments" filter={{ property_id: property.id }} defaults={{ county: property.county ?? undefined }}
        description="Recorded with the county clerk. The deed reference is filled from the tax list; add mortgages and other filings with their book/page or instrument number."
        extraActions={clerkLinks.map((l) => <a key={l.url} className="btn-secondary btn-sm" href={l.url} target="_blank" rel="noreferrer noopener">{l.label} ↗</a>)} />
      <Query q={list}>
        {(p) => p.data.length === 0 ? null : (
          <Card title="Recorded copies" subtitle="Download the image from the county clerk’s search, then attach it here. It is stored encrypted in the Vault." pad={false}>
            <ul className="divide-y divide-line">{p.data.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                <span><strong>{humanize(String(r.kind))}</strong> {r.book ? `book ${r.book} page ${r.page}` : r.instrument_number ? `#${r.instrument_number}` : ''} {r.recorded_on ? `\u00b7 ${date(r.recorded_on as string)}` : ''}</span>
                {r.document_id ? <a className="link" href={path(`vault/${r.document_id}`)}>Open recorded copy</a> : (
                  <label className="btn-secondary btn-sm cursor-pointer">{busy === r.id ? 'Uploading\u2026' : 'Attach PDF'}
                    <input type="file" accept="application/pdf,image/*" className="sr-only" disabled={busy !== null} onChange={(e) => { const f = e.target.files?.[0]; if (f) void attach(r, f); e.target.value = ''; }} />
                  </label>
                )}
              </li>
            ))}</ul>
            <div className="px-5 pb-4"><FormError error={err} /></div>
          </Card>
        )}
      </Query>
    </div>
  );
}
