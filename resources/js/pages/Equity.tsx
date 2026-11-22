import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useCommand, useJob } from '../api/hooks';
import type { Job } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, Money, PageHeader } from '../components/ui';
import { ResourceSection } from '../components/resource';
import { JobStatus } from '../components/security';
import { humanize, num } from '../lib/format';

interface Model { grants: { grant_id: string; company: string; type: string; state: Record<string, string>; scenarios: Record<string, string | null>[]; expiring_within_90_days: boolean }[]; waterfall?: { exit_value: string; common_per_share: string; converting: string[] }[] }

export default function Equity() {
  const { path } = useWorkspace();
  const nav = useNavigate();
  const [prices, setPrices] = useState('20, 42.5, 80');
  const [job, setJob] = useState<string | null>(null);
  const model = useCommand<Record<string, unknown>, { job: Job }>('equity/model');
  const res = useJob(job);
  const m = res.data?.result as Model | undefined;
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Earnings" title="Equity compensation" description="Grants, tranches and events with share conservation: exercised options and issued shares never both count. Vest income is matched to payroll before it is treated as realized." />
      <ResourceSection type="equity_grants" onOpen={(r) => nav(path(`equity/${r.id}`))} />
      <Card title="Scenario values" subtitle="Value ranges by price, with estimated tax at assumed marginal rates">
        <div className="flex flex-wrap items-end gap-3">
          <label className="field"><span>Share prices (comma-separated)</span><input value={prices} onChange={(e) => setPrices(e.target.value)} /></label>
          <button className="btn-primary" onClick={() => model.mutate({ price_scenarios: prices.split(',').map((p) => p.trim()).filter(Boolean) }, { onSuccess: (r) => setJob(r.job.id) })}>Model</button>
          <JobStatus jobId={job} label="Equity model" />
        </div>
        {m && m.grants.map((g) => (
          <div key={g.grant_id} className="mt-6">
            <h3 className="text-base">{g.company} · {g.type.toUpperCase()} {g.expiring_within_90_days && <span className="badge bg-negative/10 text-negative">Expires within 90 days</span>}</h3>
            <p className="mt-1 text-xs text-muted">{Object.entries(g.state).map(([k, v]) => `${humanize(k)} ${num(v, 0)}`).join(' · ')}</p>
            <div className="table-wrap mt-2">
              <table className="data-table">
                <thead><tr>{Object.keys(g.scenarios[0] ?? {}).filter((k) => k !== 'note').map((k) => <th key={k}>{humanize(k)}</th>)}</tr></thead>
                <tbody>{g.scenarios.map((s, i) => <tr key={i}>{Object.entries(s).filter(([k]) => k !== 'note').map(([k, v]) => <td key={k} className="text-right">{k.includes('shares') ? num(v ?? '', 2) : <Money value={v} />}</td>)}</tr>)}</tbody>
              </table>
            </div>
            {g.scenarios[0]?.note && <p className="mt-1 text-xs text-muted">{g.scenarios[0].note}</p>}
          </div>
        ))}
      </Card>
      <ResourceSection type="company_valuations" description="409A, preferred, tender and public prices used for vested value and scenarios." />
    </div>
  );
}
