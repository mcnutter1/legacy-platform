import { useState } from 'react';
import { useCommand, useResourceList } from '../api/hooks';
import type { AnyRecord, Job } from '../api/types';
import { Card, FormError, KeyValue, Money, Notice, PageHeader, StatusBadge } from '../components/ui';
import { ChartFrame, Waterfall } from '../components/charts';
import { JobStatus } from '../components/security';
import { useScopes } from '../components/resource';
import { date, humanize } from '../lib/format';

export default function YearClose() {
  const snaps = useResourceList('annual_snapshots', { limit: 20 });
  const { scopes } = useScopes('ledger');
  const [year, setYear] = useState(String(new Date().getFullYear() - 1));
  const [scope, setScope] = useState('');
  const [job, setJob] = useState<string | null>(null);
  const build = useCommand<{ year: number; scope_id: string }, { job: Job }>('year-close');
  const close = useCommand<{ id: string }>((b) => `year-close/${b.id}/close`);
  const reopen = useCommand<{ id: string; reason: string }>((b) => `year-close/${b.id}/reopen`);
  const [open, setOpen] = useState<AnyRecord | null>(null);
  const rows = snaps.data?.data ?? [];
  const current = open ?? rows[0];
  const bridge = (current?.bridge ?? {}) as Record<string, string>;
  const data = (current?.data ?? {}) as { income?: Record<string, string | null>; spending?: string; taxes_paid?: string | null; tax_liability?: string | null; contributions?: string; withdrawals?: string };
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Year close" description="Freeze the year: income, cash flow, spending, taxes and a beginning-to-ending wealth bridge with an explicit residual. Reopening needs a reason and creates a new version." />
      <Card title="Build a snapshot">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <label className="field"><span>Year</span><input inputMode="numeric" value={year} onChange={(e) => setYear(e.target.value)} /></label>
          <label className="field"><span>Scope</span><select value={scope} onChange={(e) => setScope(e.target.value)}><option value="">— Select —</option>{scopes.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
          <div className="flex items-end gap-3 sm:col-span-2">
            <button className="btn-primary" disabled={!scope} onClick={() => build.mutate({ year: Number(year), scope_id: scope }, { onSuccess: (r) => setJob(r.job.id) })}>Build</button>
            <JobStatus jobId={job} label="Year close" />
          </div>
        </div>
        <FormError error={build.error ?? close.error ?? reopen.error} />
      </Card>
      {rows.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {rows.map((s) => <button key={s.id} className={current?.id === s.id ? 'btn-primary btn-sm' : 'btn-secondary btn-sm'} onClick={() => setOpen(s)}>{String(s.year)} v{String(s.version)} <StatusBadge status={String(s.status)} /></button>)}
        </div>
      )}
      {current && (
        <>
          <Card title={`${current.year} wealth bridge`} subtitle={`Version ${current.version} · ${humanize(String(current.status))}${current.closed_at ? ` · closed ${date(String(current.closed_at))}` : ''}`}
            actions={<>
              {current.status === 'draft' && <button className="btn-primary btn-sm" onClick={() => close.mutate({ id: current.id })}>Close year</button>}
              {current.status === 'closed' && <button className="btn-secondary btn-sm" onClick={() => { const reason = prompt('Reason for reopening (required)'); if (reason) reopen.mutate({ id: current.id, reason }); }}>Reopen</button>}
            </>}>
            <ChartFrame title="Beginning to ending net worth"
              chart={<Waterfall steps={[
                { label: 'Beginning', value: Number(bridge.beginning_net_worth ?? 0), total: true },
                { label: 'Savings', value: Number(bridge.net_savings_from_cash_flow ?? 0) }, { label: 'Markets', value: Number(bridge.investment_market_change ?? 0) },
                { label: 'Property', value: Number(bridge.property_value_change ?? 0) }, { label: 'Debt paydown', value: Number(bridge.debt_reduction ?? 0) },
                { label: 'Residual', value: Number(bridge.unexplained_residual ?? 0) }, { label: 'Ending', value: Number(bridge.ending_net_worth ?? 0), total: true },
              ]} />}
              table={<table className="data-table"><tbody>{Object.entries(bridge).map(([k, v]) => <tr key={k}><td>{humanize(k)}</td><td className="text-right"><Money value={v} /></td></tr>)}</tbody></table>} />
            {Math.abs(Number(bridge.unexplained_residual ?? 0)) > 1000 && <div className="mt-4"><Notice tone="warning">The residual is material. Check for missing balance snapshots, uncategorized transfers or unvalued assets before closing.</Notice></div>}
          </Card>
          <Card title="Income, spending and taxes">
            <KeyValue items={[...Object.entries(data.income ?? {}).map(([k, v]) => [`${humanize(k)} income`, <Money key={k} value={v} />] as [string, React.ReactNode]),
              ['Spending', <Money key="s" value={data.spending} />], ['Contributions', <Money key="c" value={data.contributions} />], ['Withdrawals', <Money key="w" value={data.withdrawals} />],
              ['Taxes paid', <Money key="t" value={data.taxes_paid} />], ['Tax liability (latest run)', <Money key="l" value={data.tax_liability} />]]} />
          </Card>
        </>
      )}
    </div>
  );
}
