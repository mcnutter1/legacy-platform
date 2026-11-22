import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { api, ws } from '../api/client';
import { useResourceList, useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { Card, FormError, Money, Notice, PageHeader } from '../components/ui';
import { date, humanize } from '../lib/format';
import { ResourceSection, useScopes } from '../components/resource';
import { JobStatus } from '../components/security';

interface Pending { id: string; engine: string; provided_by: string | null; document_id: string | null; employer: string | null; pay_date: string | null; gross: string | null; net: string | null;
  created_at: string; checks_failed: number }

/** Paystub drafts (from uploads, Claude or ChatGPT) that still need "Approve paycheck". */
function PendingPaychecks() {
  const { path } = useWorkspace();
  const q = useWsQuery<Pending[]>(['payroll-pending'], 'payroll/pending');
  const rows = q.data ?? [];
  if (rows.length === 0) return null;
  return (
    <Card title="Paychecks to approve" subtitle="Nothing reaches payroll or your tax forecast until you approve it." pad={false}>
      <div className="table-wrap">
        <table className="data-table">
          <caption className="sr-only">Paystub drafts awaiting approval</caption>
          <thead><tr><th>Pay date</th><th>Employer</th><th className="text-right">Gross</th><th className="text-right">Net</th><th>From</th><th><span className="sr-only">Action</span></th></tr></thead>
          <tbody>{rows.map((r) => (
            <tr key={r.id}>
              <td>{r.pay_date ? date(r.pay_date) : '—'}</td>
              <td>{r.employer ?? '—'}</td>
              <td className="text-right"><Money value={r.gross} cents /></td>
              <td className="text-right"><Money value={r.net} cents /></td>
              <td className="text-xs">{r.provided_by ? `Values from ${r.provided_by}` : r.document_id ? 'Read from upload' : humanize(r.engine)}
                {r.checks_failed > 0 && <span className="block text-warning">{r.checks_failed} check{r.checks_failed === 1 ? '' : 's'} to look at</span>}</td>
              <td className="text-right"><Link className="btn-primary btn-sm" to={path(`extractions/${r.id}`)}>Review &amp; approve</Link></td>
            </tr>
          ))}</tbody>
        </table>
      </div>
    </Card>
  );
}

export default function Payroll() {
  const { id } = useWorkspace();
  const qc = useQueryClient();
  const employments = useResourceList('employments', { limit: 100 });
  const { scopes } = useScopes('vault');
  const [emp, setEmp] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [job, setJob] = useState<string | null>(null);
  const [err, setErr] = useState<unknown>(null);
  const upload = async (e: FormEvent) => {
    e.preventDefault();
    if (!file || !emp) return;
    setErr(null);
    const empRec = employments.data?.data.find((x) => x.id === emp);
    const form = new FormData();
    form.set('file', file);
    form.set('scope_id', String(empRec?.scope_id ?? scopes[0]?.id ?? ''));
    form.set('doc_type', 'paystub');
    form.set('extract', 'paystub');
    form.set('employment_id', emp);
    form.set('origin', /Mobi/.test(navigator.userAgent) ? 'mobile' : 'upload');
    try {
      const r = await api<{ data: { extraction_job: { id: string } } }>(ws(id, 'vault/documents'), { method: 'POST', form });
      setJob(r.data.extraction_job.id);
      void qc.invalidateQueries({ queryKey: [id] });
    } catch (x) {
      setErr(x);
    }
  };
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Earnings" title="Payroll" description="Capture paystubs (camera, PDF or ChatGPT), review the page-backed extraction, and approve. Approval refreshes your federal and NJ forecast." />
      <PendingPaychecks />
      <Card title="Add a paystub">
        <form onSubmit={upload} className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <label className="field"><span>Employment</span><select value={emp} onChange={(e) => setEmp(e.target.value)} required><option value="">— Select —</option>{(employments.data?.data ?? []).map((e) => <option key={e.id} value={e.id}>{e._title}</option>)}</select></label>
          <label className="field sm:col-span-2"><span>Paystub (PDF or photo)</span><input type="file" accept="application/pdf,image/*" capture="environment" onChange={(e) => setFile(e.target.files?.[0] ?? null)} required /></label>
          <div className="flex items-center gap-3 sm:col-span-3">
            <button className="btn-primary" disabled={!file || !emp}>Upload & extract</button>
            <JobStatus jobId={job} label="Scan & extraction" />
          </div>
          <div className="sm:col-span-3"><FormError error={err} /></div>
        </form>
        <div className="mt-4"><Notice>Files are scanned and encrypted before reading. Extracted values appear in the Review inbox; nothing changes until you confirm them.</Notice></div>
      </Card>
      <ResourceSection type="employments" />
      <ResourceSection type="payroll_snapshots" title="Approved paychecks" />
      <ResourceSection type="tax_documents" title="W-2s and tax forms" description="Annual W-2s are the authoritative reconciliation source; corrected forms supersede originals." />
    </div>
  );
}
