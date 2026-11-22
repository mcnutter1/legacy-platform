import { Link } from 'react-router';
import { useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { Card, Empty, Money, Notice, PageHeader, Query, Stat, StatusBadge } from '../components/ui';
import { BarChart, ChartFrame } from '../components/charts';
import { date, humanize, money, relative } from '../lib/format';

interface HomeData {
  as_of: string; net_worth: string; total_assets: string; total_liabilities: string; liquid_assets: string;
  categories: { section: string; category: string; value: string }[]; unvalued: number; issues: { code: string; message: string }[];
  tax: { year: number; status: string; balance_due: string | null; refund: string | null; total_tax: string | null; run_id: string; as_of: string } | null;
  due: { kind: string; label: string; due_on: string; amount: string | null; id: string }[]; review_open: number;
  recent: { type: string; id: string; label: string; at: string }[];
  sources: { id: string; label: string; provider: string; status: string; last_success_at: string | null; stale: boolean }[];
  methodology: string; prompts: { code: string; message: string }[];
}

export default function Home() {
  const { path, me } = useWorkspace();
  const q = useWsQuery<HomeData>(['home'], 'home');
  return (
    <div>
      <PageHeader eyebrow="Overview" title={`Good ${new Date().getHours() < 12 ? 'morning' : new Date().getHours() < 18 ? 'afternoon' : 'evening'}${me.user.name ? `, ${me.user.name.split(' ')[0]}` : ''}`}
        description="Where the family stands today, what is due, and what needs your attention." />
      <Query q={q}>
        {(d) => (
          <div className="space-y-6">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <Stat label="Net worth" value={money(d.net_worth)} hint={<>As of {date(d.as_of)} · <Link className="link" to={path('net-worth')}>details</Link></>} />
              <Stat label="Liquid assets" value={money(d.liquid_assets)} hint="Cash and daily-liquid investments" />
              <Stat label="Liabilities" value={money(d.total_liabilities)} hint="Mortgages, loans and lines" />
              <Stat label={d.tax ? `${d.tax.year} tax position` : 'Tax position'}
                value={d.tax ? (Number(d.tax.balance_due ?? 0) > 0 ? money(d.tax.balance_due) : money(d.tax.refund ?? 0)) : '—'}
                tone={d.tax && Number(d.tax.balance_due ?? 0) > 0 ? 'negative' : 'positive'}
                hint={d.tax ? <>{Number(d.tax.balance_due ?? 0) > 0 ? 'Projected balance due' : 'Projected refund'} · <StatusBadge status={d.tax.status} /></> : <Link className="link" to={path('tax')}>Run a tax forecast</Link>} />
            </div>
            {(d.unvalued > 0 || d.issues.length > 0) && (
              <Notice tone="warning">
                {d.unvalued > 0 && <>{d.unvalued} item(s) have no current value and are excluded from totals. </>}
                {d.issues.slice(0, 2).map((i) => i.message).join(' ')}
              </Notice>
            )}
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
              <Card title="Balance sheet" subtitle={d.methodology} className="xl:col-span-2">
                <ChartFrame title="Assets and liabilities by category"
                  chart={<BarChart items={d.categories.map((c) => ({ label: c.category, value: (c.section === 'liabilities' ? -1 : 1) * Number(c.value) }))} />}
                  table={<table className="data-table"><thead><tr><th>Category</th><th>Section</th><th className="text-right">Value</th></tr></thead>
                    <tbody>{d.categories.map((c) => <tr key={c.section + c.category}><td>{c.category}</td><td>{humanize(c.section)}</td><td className="text-right"><Money value={c.value} /></td></tr>)}</tbody></table>} />
              </Card>
              <Card title="Due soon" actions={<Link className="btn-ghost btn-sm" to={path('review')}>Review inbox ({d.review_open})</Link>}>
                {d.due.length === 0 ? <p className="text-sm text-muted">Nothing due in the next 45 days.</p> : (
                  <ul className="divide-y divide-line">
                    {d.due.map((x) => (
                      <li key={x.kind + x.id} className="flex items-start justify-between gap-3 py-2.5 text-sm">
                        <span>{x.label}</span>
                        <span className="num shrink-0 text-right text-muted">{date(x.due_on)}{x.amount && <><br /><Money value={x.amount} /></>}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            </div>
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
              <Card title="Data sources">
                {d.sources.length === 0 ? <Empty title="No sources yet"><Link className="link" to={path('imports')}>Import a Monarch or Snowball export</Link></Empty> : (
                  <ul className="space-y-2 text-sm">
                    {d.sources.map((s) => (
                      <li key={s.id} className="flex items-center justify-between gap-2">
                        <span>{s.label}</span>
                        <span className="text-xs">{s.stale ? <StatusBadge status="needs_review" label={s.last_success_at ? `Stale · ${relative(s.last_success_at)}` : 'Never synced'} /> : <span className="text-muted">{relative(s.last_success_at)}</span>}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
              <Card title="Recent changes">
                {d.recent.length === 0 ? <p className="text-sm text-muted">No recent activity.</p> : (
                  <ul className="space-y-2 text-sm">{d.recent.map((r) => <li key={r.type + r.id} className="flex justify-between gap-2"><span className="truncate">{r.label}</span><span className="shrink-0 text-xs text-muted">{relative(r.at)}</span></li>)}</ul>
                )}
              </Card>
              <Card title="Professional review prompts">
                {d.prompts.length === 0 ? <p className="text-sm text-muted">No open prompts.</p> : (
                  <ul className="list-disc space-y-1.5 pl-5 text-sm text-ink-soft">{d.prompts.map((p, i) => <li key={i}>{p.message}</li>)}</ul>
                )}
              </Card>
            </div>
          </div>
        )}
      </Query>
    </div>
  );
}
