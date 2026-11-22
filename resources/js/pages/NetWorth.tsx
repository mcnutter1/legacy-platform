import { useState } from 'react';
import { Link } from 'react-router';
import { useResourceList, useWsQuery } from '../api/hooks';
import type { NetWorth as NW } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, Money, Notice, PageHeader, Query, Stat } from '../components/ui';
import { ChartFrame, Donut } from '../components/charts';
import { date, money, pct, today } from '../lib/format';

const LINK: Record<string, string> = { account: 'accounts', property: 'real-estate', asset: 'assets', liability: 'debts', policy: 'insurance' };

export default function NetWorth() {
  const { path } = useWorkspace();
  const [asOf, setAsOf] = useState(today());
  const [person, setPerson] = useState('');
  const people = useResourceList('persons', { limit: 100 });
  const q = useWsQuery<NW>(['net-worth'], 'net-worth', { query: { as_of: asOf, person_id: person || undefined } });
  return (
    <div>
      <PageHeader eyebrow="Wealth" title="Net worth" description="Every figure drills to its source record. Each asset and liability is counted once."
        actions={<>
          <label className="field"><span className="sr-only">As of</span><input type="date" value={asOf} max={today()} onChange={(e) => setAsOf(e.target.value)} /></label>
          <label className="field"><span className="sr-only">View</span>
            <select value={person} onChange={(e) => setPerson(e.target.value)}>
              <option value="">Consolidated (100% of visible)</option>
              {(people.data?.data ?? []).map((p) => <option key={p.id} value={p.id}>{p._title}'s share</option>)}
            </select>
          </label>
        </>} />
      <Query q={q}>
        {(d) => {
          const assets = d.categories.filter((c) => c.section === 'assets');
          return (
            <div className="space-y-6">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Stat label="Net worth" value={money(d.net_worth)} hint={`${d.view === 'personal' ? 'Personal ownership view' : 'Consolidated'} · ${date(d.as_of)}`} />
                <Stat label="Total assets" value={money(d.total_assets)} hint={`Liquid ${money(d.liquid_assets)} (${pct(Number(d.liquid_assets) / Math.max(1, Number(d.total_assets)))})`} />
                <Stat label="Total liabilities" value={money(d.total_liabilities)} />
              </div>
              {d.issues.length > 0 && <Notice tone="warning"><ul className="list-disc pl-5">{d.issues.map((i, k) => <li key={k}>{i.message}</li>)}</ul></Notice>}
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card title="Asset mix">
                  <ChartFrame title="Share of assets by category" chart={<Donut items={assets.map((c) => ({ label: c.category, value: Number(c.value) }))} />}
                    table={<table className="data-table"><tbody>{assets.map((c) => <tr key={c.category}><td>{c.category}</td><td className="text-right"><Money value={c.value} /></td></tr>)}</tbody></table>} />
                </Card>
                <Card title="Detail" className="lg:col-span-2" pad={false}>
                  <div className="table-wrap">
                    <table className="data-table">
                      <thead><tr><th>Item</th><th>Source</th><th>As of</th><th className="text-right">Value</th></tr></thead>
                      {d.categories.map((c) => (
                        <tbody key={c.section + c.category}>
                          <tr className="bg-sunken/40"><td colSpan={3} className="font-medium">{c.category}{c.section === 'liabilities' && <span className="ml-2 text-xs text-muted">liability</span>}</td><td className="text-right font-medium"><Money value={c.section === 'liabilities' ? `-${c.value}` : c.value} /></td></tr>
                          {c.items.map((i) => (
                            <tr key={i.type + i.id}>
                              <td className="pl-6">{LINK[i.type] ? <Link className="link" to={path(`${LINK[i.type]}${i.type === 'account' || i.type === 'property' ? `/${i.id}` : ''}`)}>{i.label}</Link> : i.label}
                                {i.share !== '1' && <span className="ml-1 text-xs text-muted">({pct(i.share, 0)} share)</span>}</td>
                              <td className="text-muted">{i.method}</td>
                              <td className="num text-muted">{date(i.as_of)}</td>
                              <td className="text-right"><Money value={i.value} /></td>
                            </tr>
                          ))}
                        </tbody>
                      ))}
                    </table>
                  </div>
                </Card>
              </div>
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card title="Not valued" subtitle="Excluded from totals — never treated as zero">
                  {d.unvalued.length === 0 ? <p className="text-sm text-muted">Everything has a value.</p> : <ul className="space-y-1 text-sm">{d.unvalued.map((u) => <li key={u.type + u.id}>{u.label} <span className="text-muted">· {u.category}</span></li>)}</ul>}
                </Card>
                <Card title="Equity compensation" subtitle="Vested intrinsic value; excluded from net worth">
                  {d.equity_compensation.length === 0 ? <p className="text-sm text-muted">No grants.</p> : (
                    <ul className="space-y-2 text-sm">{d.equity_compensation.map((g) => <li key={g.grant_id} className="flex justify-between"><span>{g.company} {g.type.toUpperCase()}</span><Money value={g.intrinsic_value} /></li>)}</ul>
                  )}
                </Card>
                <Card title="Contingent liabilities" subtitle="Guarantees and contingent commitments">
                  {d.contingent_liabilities.length === 0 ? <p className="text-sm text-muted">None recorded.</p> : <ul className="space-y-1 text-sm">{d.contingent_liabilities.map((c, i) => <li key={i} className="flex justify-between"><span>{c.label}</span><Money value={c.amount} /></li>)}</ul>}
                </Card>
              </div>
              <p className="text-xs text-muted">{d.methodology}</p>
            </div>
          );
        }}
      </Query>
    </div>
  );
}
