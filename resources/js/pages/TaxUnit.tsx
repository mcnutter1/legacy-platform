import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import { useCommand, useResource, useResourceList, useWsQuery } from '../api/hooks';
import type { AnyRecord, Job } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, FormError, Money, Notice, PageHeader, Query, StatusBadge, Tabs } from '../components/ui';
import { RefLabel, ResourceSection } from '../components/resource';
import { JobStatus } from '../components/security';
import { date, humanize } from '../lib/format';

interface Estimates { run_id: string; run_status: string; plan: { jurisdiction: string; due_on: string; installment: number; amount: string }[]; note: string }

const WHAT_IF: { key: string; label: string; path: string[] }[] = [
  { key: 'hsa', label: 'HSA contributions', path: ['adjustments', 'hsa'] },
  { key: 'ira', label: 'Deductible IRA contribution', path: ['adjustments', 'ira_deductible'] },
  { key: 'charity', label: 'Cash charitable gifts', path: ['itemized', 'charitable_cash'] },
  { key: 'iso', label: 'ISO bargain element (AMT)', path: ['amt', 'iso_bargain_element'] },
  { key: 'fed_est', label: 'Federal estimated payments', path: ['payments', 'federal_estimated'] },
  { key: 'nj_est', label: 'NJ estimated payments', path: ['payments', 'nj_estimated'] },
];

function buildOverrides(values: Record<string, string>, filing: string) {
  const out: Record<string, Record<string, string> | string> = {};
  for (const w of WHAT_IF) {
    const v = values[w.key];
    if (v === undefined || v === '') continue;
    const [group, field] = w.path as [string, string];
    out[group] = { ...((out[group] as Record<string, string>) ?? {}), [field]: v };
  }
  if (filing) out.filing_status = filing;
  return out;
}

export default function TaxUnit() {
  const { unit = '' } = useParams();
  const { path } = useWorkspace();
  const nav = useNavigate();
  const u = useResource('tax_units', unit);
  const [tab, setTab] = useState('runs');
  const [job, setJob] = useState<string | null>(null);
  const [stratJob, setStratJob] = useState<string | null>(null);
  const [whatIf, setWhatIf] = useState<Record<string, string>>({});
  const [filing, setFiling] = useState('');
  const [scenario, setScenario] = useState('what-if');
  const [hdhp, setHdhp] = useState(false);
  const run = useCommand<{ kind: string; scenario?: string; overrides?: unknown }, { run_id: string; job: Job }>(`tax/units/${unit}/runs`);
  const strategies = useCommand<Record<string, unknown>, { job: Job }>(`tax/units/${unit}/strategies`);
  const estimates = useWsQuery<Estimates>(['estimates', unit], `tax/units/${unit}/estimates`, { enabled: tab === 'estimates', retry: false });
  const saveEstimates = useCommand(`tax/units/${unit}/estimates`);
  const strategyRows = useResourceList('tax_strategies', { filter: { tax_unit_id: unit }, limit: 50 }, tab === 'strategies');
  const decide = useCommand<{ id: string; status: string }>((b) => `tax/strategies/${b.id}/decision`);
  const start = (kind: string, extra: { scenario?: string; overrides?: unknown } = {}) =>
    run.mutate({ kind, ...extra }, { onSuccess: (r) => setJob(r.job.id) });
  return (
    <Query q={u}>
      {(t) => (
        <div className="space-y-6">
          <PageHeader eyebrow={`Tax unit · ${t.tax_year}`} title={String(t.name ?? `${t.tax_year} ${humanize(String(t.filing_status))}`)}
            description={<>{humanize(String(t.filing_status))} · <RefLabel type="persons" id={t.primary_person_id as string} />{t.spouse_person_id ? <> & <RefLabel type="persons" id={t.spouse_person_id as string} /></> : null}</>}
            actions={<>
              <button className="btn-secondary" disabled={run.isPending} onClick={() => start('forecast', { scenario: 'forecast' })}>Forecast</button>
              <button className="btn-primary" disabled={run.isPending} onClick={() => start('return')}>Calculate return</button>
            </>} />
          <FormError error={run.error} />
          <JobStatus jobId={job} label="Tax calculation" onDone={(j) => j.status === 'succeeded' && j.result && nav(path(`tax/runs/${(j.result as { run_id: string }).run_id}`))} />
          <Tabs value={tab} onChange={setTab} tabs={[{ id: 'runs', label: 'Runs' }, { id: 'documents', label: 'Forms' }, { id: 'inputs', label: 'Other inputs' },
            { id: 'whatif', label: 'What-if' }, { id: 'strategies', label: 'Strategies' }, { id: 'estimates', label: 'Estimated payments' }, { id: 'carry', label: 'Carryforwards' }, { id: 'dependents', label: 'Dependents' }]} />
          {tab === 'runs' && <ResourceSection type="tax_runs" filter={{ tax_unit_id: unit }} onOpen={(r) => nav(path(`tax/runs/${r.id}`))} />}
          {tab === 'documents' && <ResourceSection type="tax_documents" filter={{ tax_year: String(t.tax_year) }} defaults={{ tax_year: t.tax_year }} description="Only approved forms feed the calculation. A corrected form (e.g. W-2c) supersedes the original." />}
          {tab === 'inputs' && <ResourceSection type="tax_inputs" filter={{ tax_unit_id: unit }} defaults={{ tax_unit_id: unit }} hidden={['tax_unit_id']} description="Deductions, credits, prior-year figures and other facts not on a form." />}
          {tab === 'dependents' && <ResourceSection type="tax_unit_dependents" filter={{ tax_unit_id: unit }} defaults={{ tax_unit_id: unit }} hidden={['tax_unit_id']} />}
          {tab === 'carry' && <ResourceSection type="tax_carryforwards" filter={{ tax_unit_id: unit }} defaults={{ tax_unit_id: unit }} hidden={['tax_unit_id']} />}
          {tab === 'whatif' && (
            <Card title="What-if scenario" subtitle="Overrides are hypothetical: they create a strategy run and never change your records.">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <label className="field"><span>Scenario name</span><input value={scenario} onChange={(e) => setScenario(e.target.value)} maxLength={64} /></label>
                <label className="field"><span>Filing status</span>
                  <select value={filing} onChange={(e) => setFiling(e.target.value)}><option value="">As recorded</option>{['single', 'mfj', 'mfs', 'hoh', 'qss'].map((f) => <option key={f} value={f}>{f.toUpperCase()}</option>)}</select>
                </label>
                {WHAT_IF.map((w) => (
                  <label key={w.key} className="field"><span>{w.label} (set to)</span><input inputMode="decimal" value={whatIf[w.key] ?? ''} onChange={(e) => setWhatIf({ ...whatIf, [w.key]: e.target.value })} placeholder="As recorded" /></label>
                ))}
              </div>
              <div className="mt-4"><button className="btn-primary" onClick={() => start('strategy', { scenario, overrides: buildOverrides(whatIf, filing) })}>Run what-if</button></div>
            </Card>
          )}
          {tab === 'strategies' && (
            <div className="space-y-4">
              <Card title="Find strategies" subtitle="Each candidate is re-computed through the full engine and ranked by net current-year cash.">
                <div className="flex flex-wrap items-center gap-4">
                  <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={hdhp} onChange={(e) => setHdhp(e.target.checked)} /> Covered by an HSA-eligible plan</label>
                  <button className="btn-primary" onClick={() => strategies.mutate({ hdhp_covered: hdhp }, { onSuccess: (r) => setStratJob(r.job.id) })}>Analyze</button>
                  <JobStatus jobId={stratJob} label="Strategy analysis" />
                </div>
                <FormError error={strategies.error ?? decide.error} />
              </Card>
              <Query q={strategyRows}>
                {(p) => p.data.length === 0 ? <Notice>No strategies yet. Run an analysis after a successful calculation.</Notice> : (
                  <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    {p.data.map((s: AnyRecord) => {
                      const impact = (s.impact ?? {}) as Record<string, string>;
                      return (
                        <Card key={s.id} title={String(s.title)} subtitle={`${humanize(String(s.kind))}${s.deadline ? ` · act by ${date(String(s.deadline))}` : ''}`} actions={<StatusBadge status={String(s.status)} />}>
                          <dl className="grid grid-cols-3 gap-3 text-sm">
                            <div><dt className="text-xs text-muted">Tax saving</dt><dd><Money value={impact.current_year_tax_saving} /></dd></div>
                            <div><dt className="text-xs text-muted">Cash outlay</dt><dd><Money value={impact.cash_outlay} /></dd></div>
                            <div><dt className="text-xs text-muted">Net cash</dt><dd><Money value={impact.net_current_year_cash} sign /></dd></div>
                          </dl>
                          {impact.future_tax_note && <p className="mt-3 text-xs text-muted">{impact.future_tax_note}</p>}
                          <details className="mt-3 text-sm"><summary className="cursor-pointer text-pine">Assumptions, evidence & authority</summary>
                            <ul className="mt-2 list-disc pl-5 text-ink-soft">{((s.assumptions ?? []) as string[]).map((a) => <li key={a}>{a}</li>)}</ul>
                            <p className="mt-2 text-xs">Evidence: {((s.evidence_required ?? []) as string[]).join('; ')}</p>
                            <p className="mt-1 text-xs">{((s.authorities ?? []) as { cite: string; url: string }[]).map((a) => <a key={a.cite} className="link mr-3" href={a.url} target="_blank" rel="noreferrer noopener">{a.cite}</a>)}</p>
                          </details>
                          <div className="mt-4 flex flex-wrap gap-2">
                            {['reviewing', 'accepted', 'declined', 'implemented'].filter((x) => x !== s.status).map((x) => (
                              <button key={x} className="btn-secondary btn-sm" onClick={() => decide.mutate({ id: s.id, status: x })}>{humanize(x)}</button>
                            ))}
                          </div>
                        </Card>
                      );
                    })}
                  </div>
                )}
              </Query>
            </div>
          )}
          {tab === 'estimates' && (
            <Card title="Safe-harbor estimated payments" actions={<button className="btn-secondary btn-sm" onClick={() => saveEstimates.mutate({})}>Save as planned payments</button>}>
              <Query q={estimates}>
                {(e) => (
                  <>
                    <p className="mb-3 text-sm text-muted">Based on run {e.run_id.slice(-6)} ({e.run_status}). {e.note}</p>
                    {e.plan.length === 0 ? <Notice tone="success">No further estimated payments are needed based on projected withholding.</Notice> : (
                      <table className="data-table"><thead><tr><th>Jurisdiction</th><th>Installment</th><th>Due</th><th className="text-right">Amount</th></tr></thead>
                        <tbody>{e.plan.map((p) => <tr key={`${p.jurisdiction}${p.installment}`}><td>{p.jurisdiction}</td><td>Q{p.installment}</td><td className="num">{date(p.due_on)}</td><td className="text-right"><Money value={p.amount} cents /></td></tr>)}</tbody></table>
                    )}
                  </>
                )}
              </Query>
              <FormError error={saveEstimates.error} />
              <div className="mt-4"><ResourceSection type="tax_payments" filter={{ tax_unit_id: unit }} defaults={{ tax_unit_id: unit }} hidden={['tax_unit_id']} compact /></div>
            </Card>
          )}
        </div>
      )}
    </Query>
  );
}
