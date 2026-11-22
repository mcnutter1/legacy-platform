import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { api, ws } from '../api/client';
import { useWorkspace } from '../app/workspace';
import { Card, FormError, Notice, PageHeader } from '../components/ui';
import { ResourceSection, useScopes } from '../components/resource';
import { JobStatus } from '../components/security';

export default function Imports() {
  const { id, path } = useWorkspace();
  const nav = useNavigate();
  const qc = useQueryClient();
  const { scopes } = useScopes('ledger');
  const [provider, setProvider] = useState('monarch');
  const [scope, setScope] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [err, setErr] = useState<unknown>(null);
  const [job, setJob] = useState<{ batch: string; job: string } | null>(null);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!file) return;
    setErr(null);
    const form = new FormData();
    form.set('file', file);
    form.set('provider', provider);
    form.set('scope_id', scope || scopes[0]?.id || '');
    try {
      const r = await api<{ data: { batch_id: string; job: { id: string } } }>(ws(id, 'imports'), { method: 'POST', form });
      setJob({ batch: r.data.batch_id, job: r.data.job.id });
      void qc.invalidateQueries({ queryKey: [id] });
    } catch (x) {
      setErr(x);
    }
  };
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Imports" description="Stage a file, preview every row with match results, resolve exceptions, then commit. Re-importing the same file is a no-op; any batch can be reversed." />
      <Card title="New import">
        <form onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-4">
          <label className="field"><span>Source</span>
            <select value={provider} onChange={(e) => setProvider(e.target.value)}>
              <option value="monarch">Monarch Money (transactions CSV)</option>
              <option value="snowball">Snowball Analytics (portfolio CSV)</option>
              <option value="generic_csv">Other CSV (with profile)</option>
            </select>
          </label>
          <label className="field"><span>Scope</span><select value={scope} onChange={(e) => setScope(e.target.value)}>{scopes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
          <label className="field sm:col-span-2"><span>File</span><input type="file" accept=".csv,text/csv" onChange={(e) => setFile(e.target.files?.[0] ?? null)} required /></label>
          <div className="flex items-center gap-3 sm:col-span-4">
            <button className="btn-primary" disabled={!file}>Upload & preview</button>
            {job && <JobStatus jobId={job.job} label="Normalizing" onDone={(j) => j.status === 'succeeded' && nav(path(`imports/${job.batch}`))} />}
          </div>
          <div className="sm:col-span-4"><FormError error={err} /></div>
        </form>
        <div className="mt-4"><Notice>Accounts are matched by the account name in the export. Rows for unknown accounts wait in review so you can map them. No credentials or browser scraping are ever used.</Notice></div>
      </Card>
      <ResourceSection type="import_batches" title="Import history" onOpen={(r) => nav(path(`imports/${r.id}`))} />
      <ResourceSection type="import_profiles" description="Approved profiles (column maps, category maps, auto-post rules) are versioned; changes require re-approval." />
    </div>
  );
}
