import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useCommand, useResourceList } from '../api/hooks';
import type { Job } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, FormError, Notice, PageHeader, Tabs } from '../components/ui';
import { ResourceSection } from '../components/resource';
import { JobStatus } from '../components/security';

export default function Estate() {
  const { path } = useWorkspace();
  const nav = useNavigate();
  const scenarios = useResourceList('estate_scenarios', { limit: 50 });
  const [sc, setSc] = useState('');
  const [job, setJob] = useState<{ id: string; run: string } | null>(null);
  const [tab, setTab] = useState('documents');
  const run = useCommand<{ id: string }, { run_id: string; job: Job }>((b) => `estate/scenarios/${b.id}/runs`);
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Planning" title="Estate" description="Title and beneficiary designations control first; wills and trusts govern the rest. Model death orders to see who receives what, taxes, liquidity and gaps for counsel." />
      <Notice>The estate model is a workpaper for your attorney. It does not interpret documents or determine enforceability.</Notice>
      <Card title="Model a death order">
        <div className="flex flex-wrap items-end gap-3">
          <label className="field min-w-64"><span>Scenario</span><select value={sc} onChange={(e) => setSc(e.target.value)}><option value="">— Select —</option>{(scenarios.data?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s._title}</option>)}</select></label>
          <button className="btn-primary" disabled={!sc || run.isPending} onClick={() => run.mutate({ id: sc }, { onSuccess: (r) => setJob({ id: r.job.id, run: r.run_id }) })}>Run</button>
          {job && <JobStatus jobId={job.id} label="Estate model" onDone={(j) => j.status === 'succeeded' && nav(path(`estate/runs/${job.run}`))} />}
        </div>
        <FormError error={run.error} />
      </Card>
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'documents', label: 'Documents' }, { id: 'beneficiaries', label: 'Beneficiaries' }, { id: 'roles', label: 'Fiduciaries & agents' },
        { id: 'trusts', label: 'Trusts' }, { id: 'scenarios', label: 'Scenarios' }, { id: 'runs', label: 'Runs' }]} />
      {tab === 'documents' && <ResourceSection type="estate_documents" description="Wills, trusts, powers of attorney and directives with execution and review dates." />}
      {tab === 'beneficiaries' && <ResourceSection type="beneficiary_designations" description="Verify designations with the custodian; they override the will." />}
      {tab === 'roles' && <ResourceSection type="authority_roles" description="Executors, trustees, guardians, agents and healthcare proxies, in succession order." />}
      {tab === 'trusts' && <ResourceSection type="trust_details" description="Trust terms used by the model: type, revocability, funding status, distribution standards." />}
      {tab === 'scenarios' && <ResourceSection type="estate_scenarios" description='Death order is a JSON list of person IDs, e.g. ["<first>", "<second>"]. Use assumptions for growth and exemption options.' />}
      {tab === 'runs' && <ResourceSection type="estate_runs" onOpen={(r) => nav(path(`estate/runs/${r.id}`))} />}
    </div>
  );
}
