import { useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, ws } from '../api/client';
import { useResourceList } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { Card, FormError, Notice, PageHeader } from '../components/ui';
import { ResourceSection, useScopes } from '../components/resource';
import { JobStatus } from '../components/security';

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
