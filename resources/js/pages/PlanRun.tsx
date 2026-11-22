import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { Card, Money, Notice, PageHeader, Query, Stat, StatusBadge } from '../components/ui';
import { BarChart, ChartFrame, LineChart, color } from '../components/charts';
import { date, humanize, money } from '../lib/format';

type Row = Record<string, unknown> & { year: number };
interface PlanRun { id: string; scenario_id: string; kind: string; status: string; seed: number | null; paths: number | null; engine_version: string | null; input_hash: string | null; summary: Record<string, unknown> | null; years: Row[] | null; issues: { code: string; message: string; year?: number }[] | null; created_at: string }

const n = (v: unknown) => (typeof v === 'number' ? v : Number(v ?? 0));

export default function PlanRunPage() {
  const { run = '' } = useParams();
  const { path } = useWorkspace();
  const q = useWsQuery<PlanRun>(['plan-run', run], `planning/runs/${run}`, { refetchInterval: (query) => (query.state.data?.status === 'pending' ? 2000 : false) });
  const [real, setReal] = useState(false);
  return (
    <Query q={q}>
      {(r) => {
        const s = r.summary ?? {};
        const years = r.years ?? [];
        const defl = (y: Row) => (real ? n(y.deflator) || 1 : 1);
        return (
          <div className="space-y-6">
            <PageHeader eyebrow={`Plan run · ${humanize(r.kind)}`} title="Projection results" description={`${date(r.created_at)} · engine ${r.engine_version ?? '—'}${r.seed !== null ? ` · seed ${r.seed}` : ''}${r.paths ? ` · ${r.paths} paths` : ''}`}
              actions={<><StatusBadge status={r.status} /><Link className="btn-secondary btn-sm" to={path(`planning/scenarios/${r.scenario_id}`)}>Scenario</Link></>} />
            {r.status === 'pending' && <Notice>Running…</Notice>}
            {(r.issues ?? []).length > 0 && <Notice tone="warning"><ul className="list-disc pl-5">{(r.issues ?? []).map((i, k) => <li key={k}>{i.message}</li>)}</ul></Notice>}

            {r.kind === 'deterministic' && r.status === 'completed' && (
              <>
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                  <Stat label="Ending net worth" value={money(n(s.ending_net_worth), { compact: true })} hint={`${money(n(s.ending_net_worth_real), { compact: true })} in today's dollars`} />
                  <Stat label="Ending liquid" value={money(n(s.ending_liquid), { compact: true })} />
                  <Stat label="First shortfall" value={(s.first_shortfall_year as number | null) ?? 'None'} tone={s.first_shortfall_year ? 'negative' : 'positive'} />
                  <Stat label="Lifetime taxes" value={money(n(s.total_taxes), { compact: true })} />
                </div>
                <Card title="Net worth over time" actions={<label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={real} onChange={(e) => setReal(e.target.checked)} /> Today's dollars</label>}>
                  <ChartFrame title="Net worth and liquid assets"
                    chart={<LineChart labels={years.map((y) => y.year)} series={[
                      { label: 'Net worth', values: years.map((y) => n(y.net_worth) / defl(y)), color: color(0) },
                      { label: 'Liquid', values: years.map((y) => n(y.ending_liquid) / defl(y)), color: color(1) },
                      { label: 'Debt', values: years.map((y) => n(y.debt) / defl(y)), color: color(3), dashed: true },
                    ]} />}
                    table={<table className="data-table"><thead><tr><th>Year</th><th className="text-right">Net worth</th><th className="text-right">Liquid</th><th className="text-right">Taxes</th><th className="text-right">Shortfall</th></tr></thead>
                      <tbody>{years.map((y) => <tr key={y.year}><td>{y.year}</td><td className="text-right"><Money value={n(y.net_worth) / defl(y)} /></td><td className="text-right"><Money value={n(y.ending_liquid) / defl(y)} /></td><td className="text-right"><Money value={n(y.total_tax) / defl(y)} /></td><td className="text-right"><Money value={n(y.shortfall) / defl(y)} /></td></tr>)}</tbody></table>} />
                </Card>
                {Array.isArray(s.sensitivity) && (
                  <Card title="What moves the result" subtitle="Change in ending net worth">
                    <BarChart items={(s.sensitivity as { driver: string; ending_net_worth_delta: number }[]).map((x) => ({ label: x.driver, value: x.ending_net_worth_delta }))} />
                  </Card>
                )}
                <Card title="Annual cash flow" pad={false}>
                  <div className="table-wrap max-h-[32rem] overflow-y-auto">
                    <table className="data-table">
                      <thead><tr><th>Year</th><th>Ages</th><th className="text-right">Wages, SS & RMDs</th><th className="text-right">Spending</th><th className="text-right">Taxes</th><th className="text-right">Withdrawals</th><th className="text-right">Shortfall</th></tr></thead>
                      <tbody>{years.map((y) => {
                        const income = ['wages', 'social_security', 'rmd'].reduce((acc, k) => acc + n(y[k]), 0);
                        const spend = n(y.essential_spending) + n(y.discretionary_spending) + n(y.healthcare) + n(y.debt_service);
                        const wd = Object.values((y.withdrawals ?? {}) as Record<string, number>).reduce((acc, v) => acc + n(v), 0);
                        return (
                          <tr key={y.year}>
                            <td>{y.year}</td>
                            <td className="text-xs text-muted">{Object.values((y.ages ?? {}) as Record<string, number | null>).map((v) => v ?? '†').join(' / ')}</td>
                            <td className="text-right"><Money value={income} /></td><td className="text-right"><Money value={spend} /></td>
                            <td className="text-right"><Money value={n(y.total_tax)} /></td><td className="text-right"><Money value={wd} /></td>
                            <td className={`text-right ${n(y.shortfall) > 0 ? 'text-negative' : ''}`}><Money value={n(y.shortfall)} /></td>
                          </tr>
                        );
                      })}</tbody>
                    </table>
                  </div>
                </Card>
              </>
            )}

            {r.kind === 'montecarlo' && r.status === 'completed' && (
              <>
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                  <Stat label="Probability of success" value={`${Math.round(n(s.probability_success) * 100)}%`} tone={n(s.probability_success) >= 0.8 ? 'positive' : 'negative'} />
                  <Stat label="Essential spending covered" value={`${Math.round(n(s.probability_essential_covered) * 100)}%`} />
                  <Stat label="Median ending net worth" value={money(n((s.ending_net_worth as Record<string, number>)?.p50), { compact: true })} />
                  <Stat label="Median first shortfall" value={(s.median_first_shortfall_year as number | null) ?? 'None'} />
                </div>
                <Card title="Range of outcomes" subtitle={String(s.success_definition ?? '')}>
                  <ChartFrame title="Net worth percentiles (10th–90th band, median line)"
                    chart={<LineChart labels={years.map((y) => y.year)} band={{ low: years.map((y) => n((y.net_worth as Record<string, number>).p10)), high: years.map((y) => n((y.net_worth as Record<string, number>).p90)) }}
                      series={[{ label: 'Median', values: years.map((y) => n((y.net_worth as Record<string, number>).p50)), color: color(0) }, { label: '25th', values: years.map((y) => n((y.net_worth as Record<string, number>).p25)), color: color(2), dashed: true }]} />}
                    table={<table className="data-table"><thead><tr><th>Year</th><th className="text-right">10th</th><th className="text-right">Median</th><th className="text-right">90th</th><th className="text-right">P(shortfall so far)</th></tr></thead>
                      <tbody>{years.map((y) => { const nw = y.net_worth as Record<string, number>; return <tr key={y.year}><td>{y.year}</td><td className="text-right"><Money value={nw.p10} /></td><td className="text-right"><Money value={nw.p50} /></td><td className="text-right"><Money value={nw.p90} /></td><td className="num text-right">{Math.round(n(y.prob_shortfall_by_year) * 100)}%</td></tr>; })}</tbody></table>} />
                  <p className="mt-3 text-xs text-muted">{String(s.note ?? '')}</p>
                </Card>
              </>
            )}

            {r.kind === 'stress' && r.status === 'completed' && (
              <Card title="Stress tests" subtitle={`Base ending net worth ${money(n(s.base_ending_net_worth), { compact: true })}`}>
                <ChartFrame title="Change in ending net worth"
                  chart={<BarChart items={((s.scenarios ?? []) as { label: string; delta_vs_base: number }[]).map((x) => ({ label: x.label, value: x.delta_vs_base }))} />}
                  table={<table className="data-table"><thead><tr><th>Stress</th><th className="text-right">Ending net worth</th><th className="text-right">Change</th><th>First shortfall</th><th className="text-right">Lowest liquid</th></tr></thead>
                    <tbody>{((s.scenarios ?? []) as { scenario: string; label: string; ending_net_worth: number; delta_vs_base: number; first_shortfall_year: number | null; min_liquid: number }[]).map((x) => (
                      <tr key={x.scenario}><td>{x.label}</td><td className="text-right"><Money value={x.ending_net_worth} /></td><td className="text-right"><Money value={x.delta_vs_base} sign /></td><td className="num">{x.first_shortfall_year ?? '—'}</td><td className="text-right"><Money value={x.min_liquid} /></td></tr>
                    ))}</tbody></table>} />
              </Card>
            )}
            <p className="text-xs text-muted">Input hash <code>{r.input_hash?.slice(0, 16)}</code>. Projections are estimates conditional on assumptions; they are not guarantees.</p>
          </div>
        );
      }}
    </Query>
  );
}
