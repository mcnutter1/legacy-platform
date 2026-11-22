import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useCommand, useResource, useResourceList, useWsQuery } from '../api/hooks';
import type { AnyRecord, Job } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, FormError, KeyValue, Notice, PageHeader, Query, StatusBadge } from '../components/ui';
import { JobStatus } from '../components/security';
import { date, humanize } from '../lib/format';

interface Version { id: string; version_no: number; original_filename: string; detected_mime: string; size_bytes: number; sha256: string; page_count: number | null; scan_status: string; content_flags: Record<string, unknown> | null; created_at: string }
type Field = { current?: string | null; ytd?: string | null; value?: string | null; confidence: number; page: number; quote: string };
const OPEN = new Set(['clean', 'ready']);
const flagList = (f: Record<string, unknown> | null) => Object.entries(f ?? {}).filter(([k, v]) => v === true && k !== 'pages').map(([k]) => humanize(k));

export function ExtractionReview({ ex }: { ex: AnyRecord }) {
  const employments = useResourceList('employments', { limit: 100 });
  const fields = (ex.fields ?? {}) as Record<string, Field>;
  const [emp, setEmp] = useState(String(ex.target_id ?? ''));
  const [vals, setVals] = useState<Record<string, { current?: string; ytd?: string } | string>>({});
  const [runKind, setRunKind] = useState('regular');
  const [ack, setAck] = useState(false);
  const approve = useCommand<Record<string, unknown>>(`vault/extractions/${ex.id}/approve-paystub`);
  const reject = useCommand(`vault/extractions/${ex.id}/reject`);
  const checks = (approve.error as { details?: { checks?: { code: string; message: string }[] } } | null)?.details?.checks;
  if (ex.schema !== 'paystub') {
    return (
      <Card title={`${humanize(String(ex.schema))} extraction`} actions={<StatusBadge status={String(ex.status)} />}>
        <table className="data-table"><tbody>{Object.entries(fields).map(([k, f]) => <tr key={k}><td>{humanize(k)}</td><td className="num">{f.value ?? f.current ?? '—'}</td><td className="text-xs text-muted">p.{f.page} · {Math.round(f.confidence * 100)}%</td></tr>)}</tbody></table>
        <p className="mt-3 text-xs text-muted">Enter confirmed values on the matching record (e.g. Tax forms); this extraction is evidence only.</p>
      </Card>
    );
  }
  return (
    <Card title="Paystub extraction" subtitle={ex.engine === 'assistant' ? `Values provided by ${String(ex.model ?? 'a connected app')}` : `${humanize(String(ex.engine))}${ex.model ? ` · ${ex.model}` : ''}`} actions={<StatusBadge status={String(ex.status)} />}>
      {ex.status === 'needs_review' ? (
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); approve.mutate({ employment_id: emp, fields: vals, run_kind: runKind, acknowledge_checks: ack }); }}>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <label className="field"><span>Employment</span><select value={emp} onChange={(e) => setEmp(e.target.value)} required><option value="">— Select —</option>{(employments.data?.data ?? []).map((x) => <option key={x.id} value={x.id}>{x._title}</option>)}</select></label>
            <label className="field"><span>Pay date</span><input type="date" defaultValue={fields.pay_date?.value ?? ''} onChange={(e) => setVals({ ...vals, pay_date: e.target.value })} required /></label>
            <label className="field"><span>Run type</span><select value={runKind} onChange={(e) => setRunKind(e.target.value)}>{['regular', 'off_cycle', 'bonus', 'correction', 'final', 'equity'].map((k) => <option key={k} value={k}>{humanize(k)}</option>)}</select></label>
          </div>
          <div className="table-wrap">
            <table className="data-table">
              <thead><tr><th>Field</th><th>This period</th><th>Year to date</th><th>Evidence</th></tr></thead>
              <tbody>{Object.entries(fields).filter(([k]) => !['pay_date', 'period_start', 'period_end', 'employer', 'employee'].includes(k)).map(([k, f]) => {
                const v = (vals[k] ?? {}) as { current?: string; ytd?: string };
                return (
                  <tr key={k} className={f.confidence < 0.7 ? 'bg-gold-tint/40' : ''}>
                    <td>{humanize(k)}</td>
                    <td><input aria-label={`${k} current`} className="input min-h-9 w-32" inputMode="decimal" defaultValue={f.current ?? ''} onChange={(e) => setVals({ ...vals, [k]: { ...v, current: e.target.value } })} /></td>
                    <td><input aria-label={`${k} year to date`} className="input min-h-9 w-32" inputMode="decimal" defaultValue={f.ytd ?? ''} onChange={(e) => setVals({ ...vals, [k]: { ...v, ytd: e.target.value } })} /></td>
                    <td className="text-xs text-muted">{ex.engine === 'assistant' ? f.quote : <>p.{f.page} · {Math.round(f.confidence * 100)}% {f.quote && <q className="block max-w-xs truncate">{f.quote}</q>}</>}</td>
                  </tr>
                );
              })}</tbody>
            </table>
          </div>
          {checks && (
            <Notice tone="warning">
              <ul className="list-disc pl-5">{checks.map((c) => <li key={c.code}>{c.message}</li>)}</ul>
              <label className="mt-2 flex items-center gap-2"><input type="checkbox" checked={ack} onChange={(e) => setAck(e.target.checked)} /> I reviewed these and the values are correct.</label>
            </Notice>
          )}
          <FormError error={approve.error ?? reject.error} />
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-secondary" onClick={() => reject.mutate({})}>Reject</button>
            <button className="btn-primary" disabled={!emp || approve.isPending}>Approve paycheck</button>
          </div>
        </form>
      ) : <p className="text-sm text-muted">{ex.status === 'approved' ? `Approved ${date(String(ex.approved_at))}.` : humanize(String(ex.status))}</p>}
    </Card>
  );
}

export default function DocumentDetail() {
  const { id = '' } = useParams();
  const { path } = useWorkspace();
  const nav = useNavigate();
  const doc = useResource('documents', id);
  const versions = useWsQuery<Version[]>(['doc-versions', id], `vault/documents/${id}/versions`);
  const extractions = useResourceList('document_extractions', { filter: { document_id: id }, limit: 20 });
  const download = useCommand<{ disposition: string; version_id?: string }, { url: string }>(`vault/documents/${id}/download`);
  const extract = useCommand<{ schema: string }, { job: Job }>(`vault/documents/${id}/extract`);
  const hold = useCommand<{ hold: boolean }>(`vault/documents/${id}/legal-hold`);
  const release = useCommand(`vault/documents/${id}/release`);
  const purge = useCommand(`vault/documents/${id}/purge`);
  const [schema, setSchema] = useState('paystub');
  const [job, setJob] = useState<string | null>(null);
  const open = (disposition: string, version_id?: string) => download.mutate({ disposition, version_id }, { onSuccess: (r) => { window.location.href = r.url; } });
  return (
    <Query q={doc}>
      {(d) => (
        <div className="space-y-6">
          <PageHeader eyebrow={`Document · ${humanize(String(d.doc_type))}`} title={String(d.title)} description={d.tax_year ? `Tax year ${d.tax_year}` : undefined}
            actions={<>
              <StatusBadge status={String(d.status)} />
              {d.legal_hold ? <span className="badge bg-gold-tint text-warning">Legal hold</span> : null}
              {OPEN.has(String(d.status)) && <button className="btn-primary btn-sm" onClick={() => open('attachment')}>Download</button>}
              {OPEN.has(String(d.status)) && <button className="btn-secondary btn-sm" onClick={() => open('inline')}>Open</button>}
            </>} />
          <FormError error={download.error ?? hold.error ?? release.error ?? purge.error} />
          {d.status === 'quarantined' && <Notice tone="warning">This file is quarantined until scanning completes{d.rejection_reason ? `: ${d.rejection_reason}` : ''}. A steward can release it after a clean or unavailable scan. <button className="link" onClick={() => release.mutate({})}>Release</button></Notice>}
          <Card title="Details">
            <KeyValue items={[['Retention', humanize(String(d.retention_policy ?? 'indefinite'))], ['Retain until', date(d.retain_until as string)], ['Original location', String(d.original_location ?? '—')], ['Origin', humanize(String(d.origin ?? ''))], ['Uploaded', date(d.created_at as string)]]} />
            <div className="mt-4 flex flex-wrap gap-2">
              <button className="btn-secondary btn-sm" onClick={() => hold.mutate({ hold: !d.legal_hold })}>{d.legal_hold ? 'Remove legal hold' : 'Place legal hold'}</button>
              <button className="btn-danger btn-sm" disabled={!!d.legal_hold} onClick={() => { if (confirm('Permanently destroy all versions of this document? This cannot be undone.')) purge.mutate({}, { onSuccess: () => nav(path('vault')) }); }}>Purge</button>
            </div>
          </Card>
          <Card title="Versions" pad={false}>
            <Query q={versions}>
              {(v) => (
                <table className="data-table">
                  <thead><tr><th>#</th><th>File</th><th>Type</th><th className="text-right">Size</th><th>Scan</th><th>Uploaded</th><th /></tr></thead>
                  <tbody>{v.map((x) => (
                    <tr key={x.id}>
                      <td>{x.version_no}</td><td>{x.original_filename}{flagList(x.content_flags).length ? <span className="ml-2 text-xs text-warning">{flagList(x.content_flags).join(', ')}</span> : null}</td>
                      <td className="text-xs text-muted">{x.detected_mime}</td><td className="num text-right">{(x.size_bytes / 1024).toFixed(0)} KB</td>
                      <td><StatusBadge status={x.scan_status} /></td><td className="num">{date(x.created_at)}</td>
                      <td>{OPEN.has(String(d.status)) && <button className="link text-xs" onClick={() => open('attachment', x.id)}>Download</button>}</td>
                    </tr>
                  ))}</tbody>
                </table>
              )}
            </Query>
          </Card>
          <Card title="Extract data" subtitle="Reads the document into a structured draft for your review. Uses AI only if enabled and consented; otherwise the text layer.">
            <div className="flex flex-wrap items-end gap-3">
              <label className="field"><span>Schema</span><select value={schema} onChange={(e) => setSchema(e.target.value)}>{['paystub', 'w2', '1099', 'statement', 'policy_declarations', 'appraisal'].map((s) => <option key={s} value={s}>{humanize(s)}</option>)}</select></label>
              <button className="btn-secondary" disabled={!OPEN.has(String(d.status))} onClick={() => extract.mutate({ schema }, { onSuccess: (j) => setJob(j.job.id) })}>Extract</button>
              <JobStatus jobId={job} label="Extraction" />
            </div>
            <FormError error={extract.error} />
          </Card>
          {(extractions.data?.data ?? []).map((ex) => <ExtractionReview key={ex.id} ex={ex} />)}
        </div>
      )}
    </Query>
  );
}
