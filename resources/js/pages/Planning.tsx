import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useResourceList, useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { Card, Money, Notice, PageHeader, Query } from '../components/ui';
import { ResourceSection } from '../components/resource';
import { date, money } from '../lib/format';

interface Baseline { from: string; to: string; complete_months: number; monthly_average: number; annualized: number; by_category: { category: string; monthly: number }[]; excluded_one_offs: { id: string; posted_on: string; category: string; amount: number }[]; exclusions: string }
interface Compare { baseline_id: string; scenarios: { scenario_id: string; name: string; is_baseline: boolean; differences: { path: string; baseline: unknown; scenario: unknown }[]; runs: Record<string, { id: string; summary: Record<string, unknown> }> }[] }

export default function Planning() {
  const { path } = useWorkspace();
  const nav = useNavigate();
  const [showBaseline, setShowBaseline] = useState(false);
  const baseline = useWsQuery<Baseline>(['spending-baseline'], 'planning/spending-baseline', { enabled: showBaseline });
  const scenarios = useResourceList('plan_scenarios', { limit: 100 });
  const [pick, setPick] = useState<string[]>([]);
  const cmp = useWsQuery<Compare>(['plan-compare', ...pick], 'planning/compare', { query: { scenarios: pick }, enabled: pick.length >= 2 });
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Planning" title="Retirement & goals" description="Annual cash-flow projections with taxes, Monte Carlo ranges and stress tests. Every result is conditional on the scenario's stated assumptions." />
      <ResourceSection type="plans" description="A plan covers one or more people over a horizon. Combined plans need each participant's consent." />
      <ResourceSection type="plan_scenarios" onOpen={(r) => nav(path(`planning/scenarios/${r.id}`))} description="Branch a scenario to change one thing at a time; comparisons list exactly which assumptions differ." />
      <Card title="Compare scenarios">
        <div className="flex flex-wrap gap-3">
          {(scenarios.data?.data ?? []).map((s) => (
            <label key={s.id} className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={pick.includes(s.id)} disabled={!pick.includes(s.id) && pick.length >= 4} onChange={(e) => setPick((p) => (e.target.checked ? [...p, s.id] : p.filter((x) => x !== s.id)))} /> {s._title}
            </label>
          ))}
        </div>
        {pick.length >= 2 && (
          <Query q={cmp}>
            {(c) => (
              <div className="table-wrap mt-4">
                <table className="data-table">
                  <thead><tr><th>Scenario</th><th className="text-right">Ending net worth</th><th className="text-right">Success probability</th><th>First shortfall</th><th>Differences from baseline</th></tr></thead>
                  <tbody>{c.scenarios.map((s) => (
                    <tr key={s.scenario_id}>
                      <td>{s.name}{s.is_baseline && <span className="badge ml-2 bg-pine-tint text-pine">Baseline</span>}</td>
                      <td className="text-right"><Money value={s.runs.deterministic?.summary.ending_net_worth as number | undefined} /></td>
                      <td className="num text-right">{s.runs.montecarlo ? `${Math.round(Number(s.runs.montecarlo.summary.probability_success) * 100)}%` : '—'}</td>
                      <td className="num">{(s.runs.deterministic?.summary.first_shortfall_year as number | null) ?? '—'}</td>
                      <td className="text-xs text-ink-soft">{s.differences.slice(0, 6).map((d) => `${d.path}: ${JSON.stringify(d.baseline)} → ${JSON.stringify(d.scenario)}`).join('; ') || (s.is_baseline ? '' : 'None')}</td>
                    </tr>
                  ))}</tbody>
                </table>
                <p className="mt-2 text-xs text-muted">Run deterministic and Monte Carlo projections on each scenario to fill in results.</p>
              </div>
            )}
          </Query>
        )}
      </Card>
      <Card title="Spending baseline" subtitle="From categorized transactions; use it to set essential and discretionary spending." actions={!showBaseline && <button className="btn-secondary btn-sm" onClick={() => setShowBaseline(true)}>Calculate</button>}>
        {showBaseline && (
          <Query q={baseline}>
            {(b) => (
              <div className="space-y-3">
                <p className="text-sm">{date(b.from)} – {date(b.to)} ({b.complete_months} months): <strong>{money(b.monthly_average)}</strong>/month, <strong>{money(b.annualized)}</strong>/year.</p>
                <table className="data-table"><tbody>{b.by_category.slice(0, 15).map((c) => <tr key={c.category}><td>{c.category}</td><td className="text-right"><Money value={c.monthly} />/mo</td></tr>)}</tbody></table>
                {b.excluded_one_offs.length > 0 && <Notice>{b.excluded_one_offs.length} one-off item(s) excluded totaling {money(b.excluded_one_offs.reduce((a, x) => a + x.amount, 0))}.</Notice>}
                <p className="text-xs text-muted">{b.exclusions}</p>
              </div>
            )}
          </Query>
        )}
      </Card>
      <ResourceSection type="family_goals" title="Goals" />
    </div>
  );
}
