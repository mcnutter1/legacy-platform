import { useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, ws } from '../api/client';
import { useCommand, useResourceList, useSaveResource, useWsQuery } from '../api/hooks';
import type { AnyRecord } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, Dialog, FormError, KeyValue, Money, Notice, Query, Stat, StatusBadge } from './ui';
import { ResourceForm, useMetaFor, useScopes } from './resource';
import { date, humanize, money, pct } from '../lib/format';

export interface Parcel {
  pin: string; mun_code: string | null; municipality: string | null; county: string | null; block: string | null; lot: string | null; qualifier: string | null;
  location: string | null; owner: string | null; property_class: string | null; property_class_label: string | null; building: string | null; acres: number | null;
  assessed_land: string | null; assessed_improvement: string | null; assessed_total: string | null; last_year_tax: string | null; deed_book: string | null; deed_page: string | null;
  deed_date: string | null; sale_price: string | null; year_built: number | null; records_as_of: string | null; match: 'exact' | 'likely' | 'same_street' | 'nearby'; distance_m: number | null;
}
export interface LookupResult {
  query: string;
  address: { matched: string | null; line: string | null; city: string | null; state: string | null; zip: string | null; county: string | null; municipality: string | null } | null;
  parcels: Parcel[];
  valuations: { source: string; value: string; low: string | null; high: string | null; as_of: string; url: string | null; details: Record<string, string | number> }[];
  links: { kind: string; label: string; url: string }[];
  sources: { name: string; status: string; message: string }[];
}

const MATCH: Record<Parcel['match'], string> = { exact: 'Exact address', likely: 'Likely match', same_street: 'Same street', nearby: 'Nearby parcel' };

export function LookupResults({ r, pin, onPin }: { r: LookupResult; pin: string | null; onPin: (p: string) => void }) {
  return (
    <div className="space-y-4">
      {r.address ? <Notice tone="success">Verified address: <strong>{r.address.matched}</strong>{r.address.county ? ` · ${r.address.county} County` : ''}</Notice>
        : <Notice tone="warning">Address could not be verified{r.parcels.length ? '; parcel found by block and lot.' : '.'}</Notice>}
      {r.parcels.length > 0 && (
        <fieldset>
          <legend className="mb-2 text-sm font-medium">Tax parcel (NJ MOD-IV)</legend>
          <div className="space-y-2">
            {r.parcels.map((p) => (
              <label key={p.pin} className={`flex cursor-pointer gap-3 rounded-lg border p-3 text-sm ${pin === p.pin ? 'border-pine bg-pine-tint' : 'border-line'}`}>
                <input type="radio" name="parcel" checked={pin === p.pin} onChange={() => onPin(p.pin)} className="mt-1" />
                <span className="flex-1">
                  <span className="font-medium">{p.location}</span> · {p.municipality} · Block {p.block} Lot {p.lot}{p.qualifier ? ` Qual ${p.qualifier}` : ''}
                  <span className="ml-2 badge bg-sunken text-muted">{MATCH[p.match]}{p.distance_m !== null ? ` · ${p.distance_m} m` : ''}</span>
                  <span className="mt-1 block text-xs text-muted">
                    {p.property_class_label ?? p.property_class} · Assessed {money(p.assessed_total)} · Tax {money(p.last_year_tax)}
                    {p.year_built ? ` · Built ${p.year_built}` : ''}{p.sale_price ? ` · Sold ${money(p.sale_price)} ${date(p.deed_date)}` : ''}
                    {p.deed_book ? ` · Deed book ${p.deed_book} page ${p.deed_page}` : ''}
                  </span>
                </span>
              </label>
            ))}
          </div>
        </fieldset>
      )}
      {r.valuations.length > 0 && (
        <div>
          <p className="mb-2 text-sm font-medium">Automated value estimates (saved as proposals for your review)</p>
          <ul className="space-y-1 text-sm">{r.valuations.map((v) => (
            <li key={v.source}>{v.source}: <strong>{money(v.value)}</strong>{v.low && v.high ? ` (${money(v.low)} – ${money(v.high)})` : ''}
              {v.url && <a className="link ml-2" href={v.url} target="_blank" rel="noreferrer noopener">View</a>}</li>
          ))}</ul>
        </div>
      )}
      {r.links.length > 0 && (
        <div className="flex flex-wrap gap-2">{r.links.map((l) => <a key={l.url} className="btn-secondary btn-sm" href={l.url} target="_blank" rel="noreferrer noopener">{l.label} ↗</a>)}</div>
      )}
      <details className="text-xs text-muted"><summary className="cursor-pointer">Sources</summary>
        <ul className="mt-1 space-y-0.5">{r.sources.map((s) => <li key={s.name}><StatusBadge status={s.status === 'ok' ? 'active' : s.status === 'not_configured' || s.status === 'not_applicable' ? 'draft' : 'needs_review'} label={humanize(s.status)} /> {s.name} — {s.message}</li>)}</ul>
      </details>
    </div>
  );
}

function LookupForm({ initial, onResult }: { initial?: string; onResult: (r: LookupResult, q: Record<string, string>) => void }) {
  const [mode, setMode] = useState<'address' | 'parcel'>('address');
  const [address, setAddress] = useState(initial ?? '');
  const [p, setP] = useState({ mun_code: '', block: '', lot: '', qualifier: '' });
  const lookup = useCommand<Record<string, string>, LookupResult>('real-estate/lookup');
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const q = mode === 'address' ? { address } : Object.fromEntries(Object.entries(p).filter(([, v]) => v));
    lookup.mutate(q, { onSuccess: (r) => onResult(r, q) });
  };
  return (
    <form onSubmit={submit} className="space-y-3">
      <div className="flex gap-4 text-sm">
        <label className="flex items-center gap-2"><input type="radio" checked={mode === 'address'} onChange={() => setMode('address')} /> Street address</label>
        <label className="flex items-center gap-2"><input type="radio" checked={mode === 'parcel'} onChange={() => setMode('parcel')} /> NJ block &amp; lot</label>
      </div>
      {mode === 'address' ? (
        <label className="field"><span>Address</span><input value={address} onChange={(e) => setAddress(e.target.value)} placeholder="12 Ocean Ave, Example Beach, NJ 08700" required autoComplete="street-address" /></label>
      ) : (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <label className="field"><span>Municipal code</span><input value={p.mun_code} onChange={(e) => setP({ ...p, mun_code: e.target.value })} placeholder="1316" inputMode="numeric" required /></label>
          <label className="field"><span>Block</span><input value={p.block} onChange={(e) => setP({ ...p, block: e.target.value })} required /></label>
          <label className="field"><span>Lot</span><input value={p.lot} onChange={(e) => setP({ ...p, lot: e.target.value })} required /></label>
          <label className="field"><span>Qualifier</span><input value={p.qualifier} onChange={(e) => setP({ ...p, qualifier: e.target.value })} placeholder="C0001" /></label>
        </div>
      )}
      <FormError error={lookup.error} />
      <button className="btn-primary" disabled={lookup.isPending}>{lookup.isPending ? 'Searching public records…' : 'Look up'}</button>
    </form>
  );
}

/** Create a property from a verified address and its tax parcel. */
export function AddPropertyDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (id: string) => void }) {
  const { id: wsId } = useWorkspace();
  const qc = useQueryClient();
  const { scopes } = useScopes('real_estate');
  const [res, setRes] = useState<{ r: LookupResult; q: Record<string, string> } | null>(null);
  const [pin, setPin] = useState<string | null>(null);
  const [v, setV] = useState({ name: '', kind: 'primary', scope_id: '' });
  const [err, setErr] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const create = async (e: FormEvent) => {
    e.preventDefault();
    if (!res) return;
    setBusy(true); setErr(null);
    const a = res.r.address;
    try {
      const created = await api<{ data: AnyRecord }>(ws(wsId, 'r/properties'), { body: {
        name: v.name, kind: v.kind, scope_id: v.scope_id || scopes[0]?.id, address_line: a?.line ?? undefined, city: a?.city ?? undefined,
        state: a?.state ?? (pin ? 'NJ' : undefined), postal_code: a?.zip ?? undefined,
      } });
      await api(ws(wsId, `properties/${created.data.id}/public-records`), { body: { ...res.q, pin } });
      void qc.invalidateQueries({ queryKey: [wsId] });
      onCreated(created.data.id);
    } catch (x) {
      setErr(x);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Dialog open onClose={onClose} title="Add a property" wide>
      <div className="space-y-6">
        <LookupForm onResult={(r, q) => { setRes({ r, q }); setPin(r.parcels.find((p) => p.match === 'exact' || p.match === 'likely')?.pin ?? r.parcels[0]?.pin ?? null);
          setV((x) => ({ ...x, name: x.name || r.address?.line || r.parcels[0]?.location || '' })); }} />
        {res && (
          <>
            <LookupResults r={res.r} pin={pin} onPin={setPin} />
            <form onSubmit={create} className="grid grid-cols-1 gap-3 border-t border-line pt-4 sm:grid-cols-3">
              <label className="field"><span>Name</span><input value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} required /></label>
              <label className="field"><span>Type</span><select value={v.kind} onChange={(e) => setV({ ...v, kind: e.target.value })}>
                {['primary', 'second_home', 'rental_ltr', 'rental_str', 'mixed', 'land', 'commercial'].map((k) => <option key={k} value={k}>{humanize(k)}</option>)}</select></label>
              <label className="field"><span>Privacy scope</span><select value={v.scope_id} onChange={(e) => setV({ ...v, scope_id: e.target.value })}>{scopes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
              <div className="sm:col-span-3"><FormError error={err} /></div>
              <div className="flex justify-end gap-2 sm:col-span-3"><button type="button" className="btn-secondary" onClick={onClose}>Cancel</button>
                <button className="btn-primary" disabled={busy || !v.name}>{busy ? 'Saving…' : 'Create property'}</button></div>
            </form>
          </>
        )}
      </div>
    </Dialog>
  );
}

/** Refresh an existing property's public records. */
export function RefreshRecordsDialog({ property, onClose }: { property: AnyRecord; onClose: () => void }) {
  const [res, setRes] = useState<{ r: LookupResult; q: Record<string, string> } | null>(null);
  const [pin, setPin] = useState<string | null>((property.parcel_pin as string) ?? null);
  const apply = useCommand<Record<string, unknown>, { updated_fields: string[]; valuations: string[] }>(`properties/${property.id}/public-records`);
  const initial = [property.address_line, property.city, property.state, property.postal_code].filter(Boolean).join(', ');
  return (
    <Dialog open onClose={onClose} title="Public records" wide>
      <div className="space-y-6">
        {apply.data ? (
          <div className="space-y-4">
            <Notice tone="success">Updated {apply.data.updated_fields.length} field(s){apply.data.valuations.length ? ` and added ${apply.data.valuations.length} valuation(s)` : ''}.</Notice>
            <div className="flex justify-end"><button className="btn-primary" onClick={onClose}>Done</button></div>
          </div>
        ) : (
          <>
            <LookupForm initial={initial} onResult={(r, q) => { setRes({ r, q }); setPin((p) => (p && r.parcels.some((x) => x.pin === p) ? p : r.parcels[0]?.pin ?? null)); }} />
            {res && (
              <>
                <LookupResults r={res.r} pin={pin} onPin={setPin} />
                <FormError error={apply.error} />
                <div className="flex justify-end gap-2"><button className="btn-secondary" onClick={onClose}>Cancel</button>
                  <button className="btn-primary" disabled={apply.isPending} onClick={() => apply.mutate({ ...res.q, pin })}>Apply to property</button></div>
              </>
            )}
          </>
        )}
      </div>
    </Dialog>
  );
}

interface Financing {
  subject: { type: string; id: string; name: string }; value: string | null; value_method: string; value_as_of: string | null; proposed_valuations: number;
  assessed_total: string | null; loans: (AnyRecord & { current_balance: string | null })[]; loans_visible: boolean; total_debt: string; equity: string | null; loan_to_value: string | null;
}

/** Loans secured by a home, car, boat or other titled asset, with equity and valuation review. */
export function FinancingPanel({ type, id }: { type: 'property' | 'asset'; id: string }) {
  const q = useWsQuery<Financing>(['collateral', type, id], `collateral/${type}/${id}`);
  const liabilities = useResourceList('liabilities', { limit: 200 });
  const vals = useResourceList('valuations', { filter: { subject_type: type, subject_id: id }, limit: 50 });
  const save = useSaveResource('liabilities');
  const decide = useCommand<{ id: string; decision: string }>((b) => `valuations/${b.id}/decision`);
  const meta = useMetaFor('liabilities');
  const [link, setLink] = useState('');
  const [adding, setAdding] = useState(false);
  const linkable = (liabilities.data?.data ?? []).filter((l) => !(l.collateral_type === type && l.collateral_id === id));
  return (
    <Query q={q}>
      {(f) => (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Stat label="Value" value={money(f.value)} hint={`${humanize(f.value_method)}${f.value_as_of ? ` · ${date(f.value_as_of)}` : ''}`} />
            <Stat label="Secured debt" value={money(f.total_debt)} hint={`${f.loans.length} loan(s)`} />
            <Stat label="Equity" value={money(f.equity)} tone={f.equity !== null && Number(f.equity) < 0 ? 'negative' : 'positive'} />
            <Stat label="Loan-to-value" value={f.loan_to_value !== null ? pct(f.loan_to_value) : '—'} tone={Number(f.loan_to_value) > 0.8 ? 'negative' : 'neutral'} />
          </div>
          {f.assessed_total && <p className="text-xs text-muted">Tax-assessed value {money(f.assessed_total)} (municipal assessment, not market value).</p>}
          <Card title="Secured loans" actions={f.loans_visible && <button className="btn-primary btn-sm" onClick={() => setAdding(true)}>Add loan</button>} pad={false}>
            {!f.loans_visible ? <p className="p-5 text-sm text-muted">You don't have access to debts in this workspace.</p> : (
              <>
                {f.loans.length === 0 ? <p className="p-5 text-sm text-muted">No loans are linked to this {type === 'property' ? 'property' : 'asset'}.</p> : (
                  <table className="data-table">
                    <thead><tr><th>Loan</th><th>Lender</th><th className="text-right">Balance</th><th className="text-right">Rate</th><th className="text-right">Payment</th><th>Matures</th><th>Status</th><th /></tr></thead>
                    <tbody>{f.loans.map((l) => (
                      <tr key={l.id}>
                        <td>{String(l.name)} <span className="text-xs text-muted">{humanize(String(l.kind))}</span></td><td>{String(l.lender ?? '—')}</td>
                        <td className="text-right"><Money value={l.current_balance} /></td><td className="num text-right">{l.interest_rate ? pct(String(l.interest_rate), 3) : '—'}</td>
                        <td className="text-right"><Money value={l.payment_amount as string} cents /></td><td className="num">{date(l.maturity_on as string)}</td><td><StatusBadge status={String(l.status)} /></td>
                        <td><button className="btn-ghost btn-sm" onClick={() => save.mutate({ id: l.id, data: { collateral_type: 'none', collateral_id: null }, version: Number(l.edit_version) })}>Unlink</button></td>
                      </tr>
                    ))}</tbody>
                  </table>
                )}
                <div className="flex flex-wrap items-end gap-3 border-t border-line p-4">
                  <label className="field min-w-64"><span>Link an existing loan</span>
                    <select value={link} onChange={(e) => setLink(e.target.value)}><option value="">— Select —</option>
                      {linkable.map((l) => <option key={l.id} value={l.id}>{l._title}{l.collateral_type && l.collateral_type !== 'none' ? ' (secured elsewhere)' : ''}</option>)}</select></label>
                  <button className="btn-secondary" disabled={!link || save.isPending} onClick={() => {
                    const l = linkable.find((x) => x.id === link);
                    if (l) save.mutate({ id: l.id, data: { collateral_type: type, collateral_id: id }, version: l.edit_version }, { onSuccess: () => setLink('') });
                  }}>Link</button>
                  <FormError error={save.error} />
                </div>
              </>
            )}
          </Card>
          <Card title="Valuations" subtitle="Only accepted market valuations count toward net worth and equity." pad={false}>
            <Query q={vals}>
              {(p) => p.data.length === 0 ? <p className="p-5 text-sm text-muted">No valuations yet.</p> : (
                <table className="data-table">
                  <thead><tr><th>As of</th><th>Purpose</th><th className="text-right">Value</th><th>Range</th><th>Method / source</th><th>Status</th><th /></tr></thead>
                  <tbody>{p.data.map((v) => (
                    <tr key={v.id}>
                      <td className="num">{date(v.as_of as string)}</td><td>{humanize(String(v.purpose))}</td><td className="text-right"><Money value={v.value as string} /></td>
                      <td className="text-xs text-muted">{v.low && v.high ? `${money(v.low as string)} – ${money(v.high as string)}` : '—'}</td>
                      <td className="text-xs">{humanize(String(v.method))}{v.source ? ` · ${v.source}` : ''}</td><td><StatusBadge status={String(v.status)} /></td>
                      <td>{v.status === 'proposed' && <div className="flex gap-1">
                        <button className="btn-secondary btn-sm" onClick={() => decide.mutate({ id: v.id, decision: 'accept' })}>Accept</button>
                        <button className="btn-ghost btn-sm" onClick={() => decide.mutate({ id: v.id, decision: 'reject' })}>Reject</button>
                      </div>}</td>
                    </tr>
                  ))}</tbody>
                </table>
              )}
            </Query>
            <div className="px-5 pb-4"><FormError error={decide.error} /></div>
          </Card>
          <Dialog open={adding} onClose={() => setAdding(false)} title={`Add a loan secured by ${f.subject.name}`} wide>
            {meta && adding && <ResourceForm meta={meta} defaults={{ collateral_type: type, collateral_id: id, status: 'active',
              kind: type === 'property' ? 'mortgage' : 'auto' }} hidden={['collateral_type', 'collateral_id']} onSaved={() => setAdding(false)} onCancel={() => setAdding(false)} />}
          </Dialog>
        </div>
      )}
    </Query>
  );
}

export function PublicRecordsPanel({ property }: { property: AnyRecord }) {
  const p = property;
  const lookups = useResourceList('property_lookups', { filter: { property_id: p.id }, limit: 1 });
  const links = ((lookups.data?.data[0]?.result as { links?: LookupResult['links'] } | undefined)?.links) ?? [];
  const [open, setOpen] = useState(false);
  return (
    <div className="space-y-4">
      <Card title="Public records" subtitle={p.records_refreshed_at ? `${p.records_source ?? ''} · refreshed ${date(p.records_refreshed_at as string)}` : 'Not looked up yet'}
        actions={<button className="btn-primary btn-sm" onClick={() => setOpen(true)}>{p.records_refreshed_at ? 'Refresh' : 'Look up records'}</button>}>
        <KeyValue items={[
          ['Verified address', String(p.normalized_address ?? '—')], ['County / municipality', [p.county, p.municipality].filter(Boolean).join(' · ') || '—'],
          ['Block / lot / qualifier', p.block ? `${p.block} / ${p.lot ?? '—'}${p.qualifier ? ` / ${p.qualifier}` : ''}` : '—'],
          ['Parcel PIN (MOD-IV)', String(p.parcel_pin ?? '—')], ['Property class', String(p.property_class ?? '—')], ['Year built', String(p.year_built ?? '—')],
          ['Living area', p.living_area_sqft ? `${Number(p.living_area_sqft).toLocaleString()} sq ft` : '—'], ['Lot size', p.lot_acres ? `${Number(p.lot_acres)} acres` : '—'],
          ['Assessed (land + improvements)', p.assessed_total ? <span key="a"><Money value={p.assessed_total as string} /> <span className="text-xs text-muted">({money(p.assessed_land as string)} + {money(p.assessed_improvement as string)})</span></span> : '—'],
          ['Last year property tax', <Money key="t" value={p.annual_property_tax as string} cents />],
          ['Deed', p.deed_book ? `Book ${p.deed_book}, page ${p.deed_page}${p.deed_date ? ` · ${date(p.deed_date as string)}` : ''}` : '—'],
          ['Last recorded sale', <Money key="s" value={p.last_sale_price as string} />],
        ]} />
        {links.length > 0 && <div className="mt-4 flex flex-wrap gap-2">{links.map((l) => <a key={l.url} className="btn-secondary btn-sm" href={l.url} target="_blank" rel="noreferrer noopener">{l.label} ↗</a>)}</div>}
        <p className="mt-3 text-xs text-muted">County clerk systems (deeds, mortgages, liens) have no public API: open the clerk search and use the block/lot or deed book/page above, then upload copies to the Vault.</p>
      </Card>
      {open && <RefreshRecordsDialog property={p} onClose={() => setOpen(false)} />}
    </div>
  );
}
