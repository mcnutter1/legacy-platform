import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useCommand, useResource, useResourceList, useSaveResource } from '../api/hooks';
import type { Job } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, FormError, Notice, PageHeader, Query, StatusBadge, Tabs } from '../components/ui';
import { ResourceSection } from '../components/resource';
import { JobStatus } from '../components/security';

const FIELDS: { key: string; label: string; hint?: string }[] = [
  { key: 'inflation', label: 'Inflation', hint: '0.025 = 2.5%' },
  { key: 'essential_spending', label: 'Essential spending / year' },
  { key: 'discretionary_spending', label: 'Discretionary spending / year' },
  { key: 'healthcare_pre65', label: 'Healthcare before 65 / year' },
  { key: 'healthcare_65', label: 'Healthcare from 65 / year' },
  { key: 'healthcare_growth', label: 'Healthcare inflation' },
  { key: 'salary_growth', label: 'Salary growth' },
  { key: 'property_appreciation', label: 'Property appreciation' },
  { key: 'survivor_multiplier', label: 'Survivor spending multiplier', hint: '0.75 = 75%' },
  { key: 'mc_paths', label: 'Monte Carlo paths' },
];
const PERSON_FIELDS = [{ key: 'retire_age', label: 'Retirement age' }, { key: 'death_age', label: 'Planning age (life)' }, { key: 'ss_pia', label: 'Social Security PIA (monthly)' }, { key: 'ss_claim_age', label: 'SS claim age' }, { key: 'salary', label: 'Salary override' }];

export default function Scenario() {
  const { scenario = '' } = useParams();
  const { path } = useWorkspace();
  const nav = useNavigate();
  const q = useResource('plan_scenarios', scenario);
  const plan = useResource('plans', q.data?.plan_id as string | undefined);
  const persons = useResourceList('persons', { limit: 200 });
  const save = useSaveResource('plan_scenarios');
  const run = useCommand<{ kind: string; paths?: number; seed?: number }, { run_id: string; job: Job }>(`planning/scenarios/${scenario}/runs`);
  const branch = useCommand<{ name: string }, { id: string }>(`planning/scenarios/${scenario}/branch`);
  const [tab, setTab] = useState('assumptions');
  const [a, setA] = useState<Record<string, string>>({});
  const [people, setPeople] = useState<Record<string, Record<string, string>>>({});
  const [advanced, setAdvanced] = useState('');
  const [job, setJob] = useState<{ id: string; run: string } | null>(null);
  const [paths, setPaths] = useState('1000');
  useEffect(() => {
    if (!q.data) return;
    const src = (q.data.assumptions ?? {}) as Record<string, unknown>;
    setA(Object.fromEntries(FIELDS.map((f) => [f.key, src[f.key] === undefined ? '' : String(src[f.key])])));
    const rest = Object.fromEntries(Object.entries(src).filter(([k]) => !FIELDS.some((f) => f.key === k)));
    setAdvanced(JSON.stringify(rest, null, 2));
    const pp: Record<string, Record<string, string>> = {};
    for (const p of ((q.data.people ?? []) as Record<string, unknown>[])) pp[String(p.id)] = Object.fromEntries(Object.entries(p).filter(([k]) => k !== 'id').map(([k, v]) => [k, String(v)]));
    setPeople(pp);
  }, [q.data]);
  const participants = ((plan.data?.participant_person_ids ?? []) as string[]);
  const onSave = () => {
    let extra: Record<string, unknown> = {};
    try { extra = advanced.trim() ? JSON.parse(advanced) : {}; } catch { alert('Advanced assumptions must be valid JSON.'); return; }
    const num = (v: string) => (v === '' ? undefined : Number(v));
    const assumptions = { ...extra, ...Object.fromEntries(Object.entries(a).map(([k, v]) => [k, num(v)]).filter(([, v]) => v !== undefined)) };
    const peopleArr = Object.entries(people).map(([id, o]) => ({ id, ...Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '').map(([k, v]) => [k, Number(v)])) }));
    save.mutate({ id: scenario, data: { assumptions, people: peopleArr }, version: q.data?.edit_version });
  };
  const start = (kind: string) => run.mutate({ kind, ...(kind === 'montecarlo' ? { paths: Number(paths) } : {}) }, { onSuccess: (r) => setJob({ id: r.job.id, run: r.run_id }) });
  return (
    <Query q={q}>
      {(s) => (
        <div className="space-y-6">
          <PageHeader eyebrow={`Scenario · ${plan.data?._title ?? ''}`} title={String(s.name)} description={s.is_baseline ? 'Baseline scenario' : 'Alternative scenario'}
            actions={<>
              <StatusBadge status={String(s.status ?? 'draft')} />
              <button className="btn-secondary btn-sm" onClick={() => { const name = prompt('Name for the new branch', `${s.name} (alternative)`); if (name) branch.mutate({ name }, { onSuccess: (r) => nav(path(`planning/scenarios/${r.id}`)) }); }}>Branch</button>
            </>} />
          <Card title="Run">
            <div className="flex flex-wrap items-end gap-3">
              <button className="btn-primary" onClick={() => start('deterministic')}>Projection</button>
              <label className="field w-32"><span>Paths</span><input inputMode="numeric" value={paths} onChange={(e) => setPaths(e.target.value)} /></label>
              <button className="btn-secondary" onClick={() => start('montecarlo')}>Monte Carlo</button>
              <button className="btn-secondary" onClick={() => start('stress')}>Stress tests</button>
              {job && <JobStatus jobId={job.id} label="Plan run" onDone={(j) => j.status === 'succeeded' && nav(path(`planning/runs/${job.run}`))} />}
            </div>
            <FormError error={run.error ?? branch.error} />
          </Card>
          <Tabs value={tab} onChange={setTab} tabs={[{ id: 'assumptions', label: 'Assumptions' }, { id: 'people', label: 'People' }, { id: 'runs', label: 'Runs' }]} />
          {tab === 'assumptions' && (
            <Card title="Assumptions" subtitle="Rates are decimals. Blank uses the default. Accounts, debts and properties come from your records.">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                {FIELDS.map((f) => <label key={f.key} className="field"><span>{f.label}</span><input inputMode="decimal" placeholder={f.hint} value={a[f.key] ?? ''} onChange={(e) => setA({ ...a, [f.key]: e.target.value })} /></label>)}
              </div>
              <label className="field mt-4"><span>Advanced (returns by asset class, streams, spending phases, per-account overrides)</span>
                <textarea rows={8} className="font-mono text-xs" value={advanced} onChange={(e) => setAdvanced(e.target.value)} />
              </label>
              <p className="mt-2 text-xs text-muted">Example: {'{"returns": {"us_equity": {"mean": 0.065, "sd": 0.16}}, "streams": [{"name": "Consulting", "amount": 40000, "start_year": 2030, "end_year": 2034, "tax": "ordinary"}]}'}</p>
              <FormError error={save.error} />
              <div className="mt-4 flex justify-end"><button className="btn-primary" disabled={save.isPending} onClick={onSave}>Save assumptions</button></div>
            </Card>
          )}
          {tab === 'people' && (
            <Card title="Participants">
              {participants.length === 0 && <Notice>Add participants to the plan first.</Notice>}
              <div className="space-y-6">
                {participants.map((pid) => (
                  <div key={pid}>
                    <h3 className="text-base">{persons.data?.data.find((p) => p.id === pid)?._title ?? pid}</h3>
                    <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-5">
                      {PERSON_FIELDS.map((f) => <label key={f.key} className="field"><span>{f.label}</span><input inputMode="decimal" value={people[pid]?.[f.key] ?? ''} onChange={(e) => setPeople({ ...people, [pid]: { ...(people[pid] ?? {}), [f.key]: e.target.value } })} /></label>)}
                    </div>
                  </div>
                ))}
              </div>
              <p className="mt-4 text-xs text-muted">Use the PIA from each person's SSA statement (ssa.gov/myaccount). Claiming adjustments and spousal/survivor benefits are applied by the engine.</p>
              <div className="mt-4 flex justify-end"><button className="btn-primary" onClick={onSave}>Save</button></div>
            </Card>
          )}
          {tab === 'runs' && <ResourceSection type="plan_runs" filter={{ scenario_id: scenario }} onOpen={(r) => nav(path(`planning/runs/${r.id}`))} />}
        </div>
      )}
    </Query>
  );
}
