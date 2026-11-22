import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { ApiError, api, ws } from '../api/client';
import { useArchiveResource, useMeta, useResource, useResourceList, useSaveResource, useWsQuery } from '../api/hooks';
import type { AccessOverview, AnyRecord, FieldMeta, ResourceMeta } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { date, humanize, money, num } from '../lib/format';
import { Dialog, Empty, ErrorState, FormError, Loading, StatusBadge } from './ui';
import { useQuery } from '@tanstack/react-query';

const MONEY = new Set(['m']);
const DECIMAL = new Set(['q', 'p', 'r', 'f', 'pct']);

export function useScopes(domain?: string, action = 'write') {
  const q = useWsQuery<AccessOverview>(['access'], 'access', { staleTime: 60_000 });
  const scopes = (q.data?.scopes ?? []).filter((s) => !domain || (s.my_access[domain] ?? []).includes(action));
  return { ...q, scopes };
}

export function RefLabel({ type, id }: { type: string; id: string | null | undefined }) {
  const q = useResource(type, id ?? undefined);
  if (!id) return <span className="text-muted">—</span>;
  if (q.isLoading) return <span className="text-muted">…</span>;
  return <span>{q.data?._title ?? <span className="text-muted">Restricted</span>}</span>;
}

const POLY: Record<string, string> = {
  account: 'accounts', asset: 'assets', property: 'properties', liability: 'liabilities', policy: 'insurance_policies', entity: 'legal_entities',
  person: 'persons', trust: 'legal_entities', plan: 'plans', equity_grant: 'equity_grants', holding: 'holdings', document: 'documents', charity: 'contacts',
};

export function CellValue({ field, record }: { field: FieldMeta; record: AnyRecord }) {
  const v = record[field.name as keyof AnyRecord] as unknown;
  if ((record._redacted ?? []).includes(field.name)) return <span className="text-muted" title="Restricted">•••</span>;
  if (v === null || v === undefined || v === '') return <span className="text-muted">—</span>;
  if (field.type === 'ref' && field.ref) return <RefLabel type={field.ref} id={String(v)} />;
  if (field.type === 'poly' && field.poly_type_field) {
    const t = POLY[String(record[field.poly_type_field as keyof AnyRecord] ?? '')];
    return t ? <RefLabel type={t} id={String(v)} /> : <span>{String(v)}</span>;
  }
  if (MONEY.has(field.type)) return <span className="num">{money(String(v), { cents: true })}</span>;
  if (field.type === 'f') return <span className="num">{(Number(v) * 100).toFixed(2)}%</span>;
  if (DECIMAL.has(field.type)) return <span className="num">{num(String(v), 6)}</span>;
  if (field.type === 'd') return <span className="num">{date(String(v))}</span>;
  if (field.type === 'dt') return <span>{date(String(v))}</span>;
  if (field.type === 'b') return <span>{v ? 'Yes' : 'No'}</span>;
  if (field.name === 'status') return <StatusBadge status={String(v)} />;
  if (field.type === 'enum') return <span>{humanize(String(v))}</span>;
  if (field.type === 'j') return <code className="text-xs text-muted">{JSON.stringify(v).slice(0, 60)}</code>;
  return <span>{String(v)}</span>;
}

export function ResourceTable({ meta, rows, onSelect, columns }: { meta: ResourceMeta; rows: AnyRecord[]; onSelect?: (r: AnyRecord) => void; columns?: string[] }) {
  const cols = (columns ?? meta.list).map((c) => meta.fields.find((f) => f.name === c)).filter((f): f is FieldMeta => !!f);
  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>{cols.map((c) => <th key={c.name} scope="col">{humanize(c.name.replace(/_id$/, ''))}</th>)}</tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id} className={onSelect ? 'cursor-pointer' : ''} onClick={() => onSelect?.(r)}>
              {cols.map((c, i) => (
                <td key={c.name} className={MONEY.has(c.type) || DECIMAL.has(c.type) ? 'text-right' : ''}>
                  {i === 0 && onSelect ? (
                    <button className="text-left underline-offset-4 hover:underline" onClick={(e) => { e.stopPropagation(); onSelect(r); }}>
                      <CellValue field={c} record={r} />
                    </button>
                  ) : <CellValue field={c} record={r} />}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function RefSelect({ field, value, onChange, typeOverride }: { field: FieldMeta; value: string; onChange: (v: string) => void; typeOverride?: string }) {
  const type = typeOverride ?? field.ref ?? '';
  const q = useResourceList(type, { limit: 200 }, !!type);
  return (
    <select value={value} onChange={(e) => onChange(e.target.value)} required={field.required} disabled={!type}>
      <option value="">{type ? '— Select —' : 'Choose a type first'}</option>
      {(q.data?.data ?? []).map((r) => <option key={r.id} value={r.id}>{r._title ?? r.id}</option>)}
    </select>
  );
}

export function ResourceForm({ meta, record, defaults = {}, hidden = [], onSaved, onCancel }: {
  meta: ResourceMeta; record?: AnyRecord; defaults?: Record<string, unknown>; hidden?: string[]; onSaved?: (r: AnyRecord) => void; onCancel?: () => void;
}) {
  const save = useSaveResource(meta.type);
  const { scopes } = useScopes(meta.domain === 'dynamic' ? undefined : meta.domain);
  const editable = meta.fields.filter((f) => !f.readonly && !hidden.includes(f.name));
  const [vals, setVals] = useState<Record<string, unknown>>(() => {
    const init: Record<string, unknown> = { ...defaults };
    for (const f of meta.fields) {
      const v = record?.[f.name as keyof AnyRecord];
      if (v !== undefined) init[f.name] = f.type === 'j' && v !== null ? JSON.stringify(v, null, 2) : v;
    }
    if (!record && !meta.unscoped && scopes.length === 1) init.scope_id = scopes[0]?.id;
    return init;
  });
  const [jsonErr, setJsonErr] = useState<string | null>(null);
  const set = (k: string, v: unknown) => setVals((s) => ({ ...s, [k]: v }));
  const fieldErrors = save.error instanceof ApiError ? save.error.fieldErrors : {};

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setJsonErr(null);
    const data: Record<string, unknown> = {};
    for (const f of editable) {
      let v = vals[f.name];
      if (v === undefined) continue;
      if (v === '' && !f.required) v = null;
      if (f.type === 'j' && typeof v === 'string' && v.trim() !== '') {
        try { v = JSON.parse(v); } catch { setJsonErr(`${humanize(f.name)} must be valid JSON.`); return; }
      }
      if ((f.type === 'i' || f.type === 'y') && v !== null && v !== '') v = Number(v);
      if (record && JSON.stringify(v) === JSON.stringify(record[f.name as keyof AnyRecord])) continue;
      data[f.name] = v;
    }
    for (const [k, v] of Object.entries(defaults)) if (!(k in data) && !record) data[k] = v;
    if (!meta.unscoped && (vals.scope_id || !record)) data.scope_id = vals.scope_id ?? record?.scope_id;
    save.mutate({ id: record?.id, data, version: record?.edit_version }, { onSuccess: (r) => onSaved?.(r.data) });
  };

  return (
    <form onSubmit={submit} className="space-y-4" noValidate={false}>
      {!meta.unscoped && (
        <label className="field">
          <span>Privacy scope</span>
          <select value={String(vals.scope_id ?? record?.scope_id ?? '')} onChange={(e) => set('scope_id', e.target.value)} required={!record}>
            <option value="">— Select —</option>
            {scopes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </label>
      )}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {editable.map((f) => {
          const v = vals[f.name];
          const label = `${humanize(f.name.replace(/_id$/, ''))}${f.required ? ' *' : ''}`;
          const err = fieldErrors[f.name]?.[0];
          const wide = f.type === 'j' || f.type === 't';
          let input: ReactNode;
          if (f.type === 'enum') {
            input = (
              <select value={String(v ?? '')} onChange={(e) => set(f.name, e.target.value)} required={f.required}>
                <option value="">— Select —</option>
                {(f.values ?? []).map((o) => <option key={o} value={o}>{humanize(o)}</option>)}
              </select>
            );
          } else if (f.type === 'ref') {
            input = <RefSelect field={f} value={String(v ?? '')} onChange={(x) => set(f.name, x)} />;
          } else if (f.type === 'poly') {
            const t = POLY[String(vals[f.poly_type_field ?? ''] ?? '')];
            input = t ? <RefSelect field={f} typeOverride={t} value={String(v ?? '')} onChange={(x) => set(f.name, x)} />
              : <input value={String(v ?? '')} onChange={(e) => set(f.name, e.target.value)} placeholder="Record ID" />;
          } else if (f.type === 'b') {
            input = <input type="checkbox" className="h-5 w-5 min-h-0" checked={!!v} onChange={(e) => set(f.name, e.target.checked)} />;
          } else if (f.type === 'd') {
            input = <input type="date" value={String(v ?? '')} onChange={(e) => set(f.name, e.target.value)} required={f.required} />;
          } else if (f.type === 'dt') {
            input = <input type="datetime-local" value={String(v ?? '').slice(0, 16)} onChange={(e) => set(f.name, e.target.value)} required={f.required} />;
          } else if (f.type === 'j' || f.type === 't') {
            input = <textarea value={String(v ?? '')} onChange={(e) => set(f.name, e.target.value)} rows={f.type === 'j' ? 5 : 3} className={f.type === 'j' ? 'font-mono text-xs' : ''} />;
          } else if (MONEY.has(f.type) || DECIMAL.has(f.type) || f.type === 'i' || f.type === 'y') {
            input = <input inputMode="decimal" value={String(v ?? '')} onChange={(e) => set(f.name, e.target.value.replace(/[$,\s]/g, ''))} required={f.required}
              pattern={f.type === 'i' || f.type === 'y' ? '-?\\d*' : '-?\\d*(\\.\\d+)?'} />;
          } else {
            input = <input value={String(v ?? '')} onChange={(e) => set(f.name, e.target.value)} required={f.required} maxLength={f.type === 'cur' ? 3 : 255} />;
          }
          return (
            <label key={f.name} className={`field ${wide ? 'sm:col-span-2' : ''} ${f.type === 'b' ? 'flex-row items-center gap-3' : ''}`}>
              <span>{label}{f.sensitive && <span className="ml-1 text-xs text-muted">(restricted)</span>}</span>
              {input}
              {err && <span className="text-xs text-negative">{err}</span>}
            </label>
          );
        })}
      </div>
      {jsonErr && <p className="text-sm text-negative">{jsonErr}</p>}
      <FormError error={save.error} />
      <div className="flex justify-end gap-2 border-t border-line pt-4">
        {onCancel && <button type="button" className="btn-secondary" onClick={onCancel}>Cancel</button>}
        <button className="btn-primary" disabled={save.isPending}>{save.isPending ? 'Saving…' : record ? 'Save changes' : 'Create'}</button>
      </div>
    </form>
  );
}

/** List + create/edit dialogs for any registry resource, optionally filtered by a parent. */
export function ResourceSection({ type, filter = {}, defaults = {}, hidden = [], title, description, columns, emptyText, extraActions, onOpen, compact }: {
  type: string; filter?: Record<string, string>; defaults?: Record<string, unknown>; hidden?: string[]; title?: string; description?: ReactNode;
  columns?: string[]; emptyText?: ReactNode; extraActions?: ReactNode; onOpen?: (r: AnyRecord) => void; compact?: boolean;
}) {
  const metaQ = useMeta();
  const meta = metaQ.data?.resources[type];
  const [cursor, setCursor] = useState<string | null>(null);
  const [q, setQ] = useState('');
  const list = useResourceList(type, { filter, cursor, q: q || undefined, limit: compact ? 10 : 50 }, !!meta);
  const [editing, setEditing] = useState<AnyRecord | 'new' | null>(null);
  const archive = useArchiveResource(type);
  if (!meta) return metaQ.isLoading ? <Loading /> : null;
  const rows = list.data?.data ?? [];
  return (
    <section className="card">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
        <div>
          <h2 className="text-lg">{title ?? meta.label}</h2>
          {description && <p className="mt-0.5 text-xs text-muted">{description}</p>}
        </div>
        <div className="flex flex-wrap gap-2">
          {meta.searchable && !compact && <input className="input max-w-48" placeholder="Search" aria-label={`Search ${meta.label}`} value={q} onChange={(e) => { setQ(e.target.value); setCursor(null); }} />}
          {extraActions}
          {meta.creatable && <button className="btn-primary btn-sm" onClick={() => setEditing('new')}>Add</button>}
        </div>
      </div>
      <div className="card-pad">
        {list.isLoading ? <Loading /> : list.error ? <ErrorState error={list.error} retry={() => list.refetch()} /> : rows.length === 0 ? (
          <Empty title={`No ${meta.label.toLowerCase()} yet`}>{emptyText ?? (meta.creatable ? 'Add the first record to get started.' : 'Records appear here when they are created by the application.')}</Empty>
        ) : (
          <>
            <ResourceTable meta={meta} rows={rows} columns={columns} onSelect={(r) => (onOpen ? onOpen(r) : setEditing(r))} />
            {(list.data?.next_cursor || cursor) && (
              <div className="mt-4 flex justify-end gap-2">
                {cursor && <button className="btn-secondary btn-sm" onClick={() => setCursor(list.data?.prev_cursor ?? null)}>Previous</button>}
                {list.data?.next_cursor && <button className="btn-secondary btn-sm" onClick={() => setCursor(list.data?.next_cursor ?? null)}>Next</button>}
              </div>
            )}
          </>
        )}
      </div>
      <Dialog open={editing !== null} onClose={() => setEditing(null)} title={editing === 'new' ? `Add ${meta.label.replace(/s$/, '').toLowerCase()}` : (editing?._title ?? meta.label)} wide>
        {editing && (
          meta.readonly ? <RecordDetails meta={meta} record={editing as AnyRecord} /> : (
            <>
              <ResourceForm meta={meta} record={editing === 'new' ? undefined : editing} defaults={{ ...defaults, ...filter }} hidden={[...hidden, ...Object.keys(filter)]}
                onSaved={() => setEditing(null)} onCancel={() => setEditing(null)} />
              {editing !== 'new' && (
                <div className="mt-6 flex items-center justify-between border-t border-line pt-4 text-xs text-muted">
                  <span>Version {editing.edit_version} · updated {date(editing.updated_at)}</span>
                  <button className="btn-danger btn-sm" disabled={archive.isPending}
                    onClick={() => { if (confirm('Archive this record? It stays in history and audit trails.')) archive.mutate({ id: editing.id, version: editing.edit_version }, { onSuccess: () => setEditing(null) }); }}>
                    Archive
                  </button>
                </div>
              )}
            </>
          )
        )}
      </Dialog>
    </section>
  );
}

export function RecordDetails({ meta, record }: { meta: ResourceMeta; record: AnyRecord }) {
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
      {meta.fields.map((f) => (
        <div key={f.name} className={f.type === 'j' ? 'sm:col-span-2' : ''}>
          <dt className="text-xs text-muted">{humanize(f.name)}</dt>
          <dd className="mt-0.5 break-words">
            {f.type === 'j' && record[f.name as keyof AnyRecord] ? (
              <pre className="max-h-64 overflow-auto rounded bg-sunken p-2 text-xs">{JSON.stringify(record[f.name as keyof AnyRecord], null, 2)}</pre>
            ) : <CellValue field={f} record={record} />}
          </dd>
        </div>
      ))}
    </dl>
  );
}

/** Lightweight picker for selecting a record of a type (used by command forms). */
export function RecordPicker({ type, value, onChange, label, filter }: { type: string; value: string; onChange: (v: string) => void; label: string; filter?: Record<string, string> }) {
  const q = useResourceList(type, { limit: 200, filter: filter ?? {} });
  return (
    <label className="field">
      <span>{label}</span>
      <select value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">— Select —</option>
        {(q.data?.data ?? []).map((r) => <option key={r.id} value={r.id}>{r._title ?? r.id}</option>)}
      </select>
    </label>
  );
}

export function useWorkspaceFetch<T>(key: unknown[], path: string, enabled = true) {
  const { id } = useWorkspace();
  return useQuery<T>({ queryKey: [id, ...key], queryFn: () => api<{ data: T }>(ws(id, path)).then((r) => r.data), enabled });
}

export function useMetaFor(type: string): ResourceMeta | undefined {
  const m = useMeta();
  return useMemo(() => m.data?.resources[type], [m.data, type]);
}
