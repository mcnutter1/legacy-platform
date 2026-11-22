import { useState } from 'react';
import { useCommand, useJob, useResourceList } from '../api/hooks';
import type { Job } from '../api/types';
import { Card, Money, PageHeader, StatusBadge } from '../components/ui';
import { JobStatus } from '../components/security';
import { ResourceSection } from '../components/resource';
import { ChartFrame, LineChart, color } from '../components/charts';
import { date, humanize, money } from '../lib/format';

interface Strategy { months: number; debt_free: string; total_interest: string; after_tax_interest: string; interest_saved_vs_minimum: string; payoff_dates: Record<string, string> }
interface Amort { baseline: { rows: { n: number; due: string; payment: string; interest: string; principal: string; balance: string }[]; total_interest: string; payoff_date?: string; months?: number; negative_amortization: boolean }; with_extra?: { total_interest: string; payoff_date?: string; months?: number }; interest_saved?: string }

export default function Debts() {
  const debts = useResourceList('liabilities', { limit: 100 });
  const [selected, setSelected] = useState<string[]>([]);
  const [extra, setExtra] = useState('500');
  const [rate, setRate] = useState('0.32');
  const [refi, setRefi] = useState({ loan_id: '', rate: '', term_months: '360', closing_costs: '0' });
  const [jobId, setJobId] = useState<string | null>(null);
  const [amortJob, setAmortJob] = useState<string | null>(null);
  const [amortFor, setAmortFor] = useState('');
  const compare = useCommand<Record<string, unknown>, { job: Job }>('debts/compare');
  const amort = useCommand<{ id: string; extra_monthly: string }, { job: Job }>((b) => `debts/${b.id}/amortize`);
  const result = useJob(jobId);
  const amortResult = useJob(amortJob);
  const strategies = (result.data?.result as { strategies?: Record<string, Strategy & Record<string, unknown>> } | null)?.strategies;
  const a = amortResult.data?.result as Amort | undefined;
  const rows = (debts.data?.data ?? []).filter((d) => d.status === 'active');
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Debts" description="Amortization on actual due dates with per-period rounding, reconciled to statements. Compare paydown strategies against investing the difference." />
      <ResourceSection type="liabilities" />
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <Card title="Payoff strategies" subtitle="Minimum-only vs. avalanche vs. snowball, after tax">
          <fieldset className="mb-4">
            <legend className="mb-2 text-sm font-medium">Debts to include</legend>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {rows.map((d) => (
                <label key={d.id} className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={selected.includes(d.id)} onChange={(e) => setSelected((s) => (e.target.checked ? [...s, d.id] : s.filter((x) => x !== d.id)))} />
                  {String(d.name)} <span className="text-muted">({money(d.current_balance as string)})</span>
                </label>
              ))}
            </div>
          </fieldset>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="field"><span>Extra per month</span><input inputMode="decimal" value={extra} onChange={(e) => setExtra(e.target.value)} /></label>
            <label className="field"><span>Marginal tax rate</span><input inputMode="decimal" value={rate} onChange={(e) => setRate(e.target.value)} /></label>
            <label className="field"><span>Refinance (optional)</span>
              <select value={refi.loan_id} onChange={(e) => setRefi({ ...refi, loan_id: e.target.value })}><option value="">No refinance</option>{rows.map((d) => <option key={d.id} value={d.id}>{String(d.name)}</option>)}</select>
            </label>
            {refi.loan_id && <>
              <label className="field"><span>New rate</span><input inputMode="decimal" placeholder="0.0575" value={refi.rate} onChange={(e) => setRefi({ ...refi, rate: e.target.value })} /></label>
              <label className="field"><span>New term (months)</span><input inputMode="numeric" value={refi.term_months} onChange={(e) => setRefi({ ...refi, term_months: e.target.value })} /></label>
              <label className="field"><span>Closing costs</span><input inputMode="decimal" value={refi.closing_costs} onChange={(e) => setRefi({ ...refi, closing_costs: e.target.value })} /></label>
            </>}
          </div>
          <div className="mt-4 flex items-center gap-3">
            <button className="btn-primary" disabled={!selected.length || compare.isPending}
              onClick={() => compare.mutate({ liability_ids: selected, extra_monthly: extra, marginal_tax_rate: rate, refinance: refi.loan_id && refi.rate ? refi : undefined },
                { onSuccess: (r) => setJobId(r.job.id) })}>Compare</button>
            <JobStatus jobId={jobId} label="Payoff comparison" />
          </div>
          {strategies && (
            <div className="table-wrap mt-5">
              <table className="data-table">
                <thead><tr><th>Strategy</th><th>Debt-free</th><th className="text-right">Interest</th><th className="text-right">After tax</th><th className="text-right">Saved</th></tr></thead>
                <tbody>
                  {(['minimum_only', 'avalanche', 'snowball', 'custom'] as const).filter((k) => strategies[k]).map((k) => {
                    const s = strategies[k] as Strategy;
                    return <tr key={k}><td>{humanize(k)}</td><td className="num">{date(s.debt_free)}</td><td className="text-right"><Money value={s.total_interest} /></td><td className="text-right"><Money value={s.after_tax_interest} /></td><td className="text-right"><Money value={s.interest_saved_vs_minimum} /></td></tr>;
                  })}
                </tbody>
              </table>
              {strategies.invest_instead && <p className="mt-3 text-sm text-muted">Investing {money(extra)}/month instead could grow to about <strong>{money((strategies.invest_instead as unknown as { future_value: string }).future_value)}</strong> over the same horizon — but returns are uncertain while interest savings are guaranteed.</p>}
              {strategies.refinance && (() => { const r = strategies.refinance as unknown as Record<string, string | number | null>; return <p className="mt-2 text-sm">Refinance: new payment {money(r.new_payment as string, { cents: true })}, saves {money(r.monthly_saving as string, { cents: true })}/month, break-even in {r.breakeven_months ?? '—'} months, lifetime saving {money(r.lifetime_saving as string)}.</p>; })()}
            </div>
          )}
        </Card>
        <Card title="Amortization schedule">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label className="field sm:col-span-2"><span>Debt</span><select value={amortFor} onChange={(e) => setAmortFor(e.target.value)}><option value="">— Select —</option>{rows.map((d) => <option key={d.id} value={d.id}>{String(d.name)}</option>)}</select></label>
            <label className="field"><span>Extra / month</span><input inputMode="decimal" value={extra} onChange={(e) => setExtra(e.target.value)} /></label>
          </div>
          <div className="mt-4 flex items-center gap-3">
            <button className="btn-primary" disabled={!amortFor} onClick={() => amort.mutate({ id: amortFor, extra_monthly: extra }, { onSuccess: (r) => setAmortJob(r.job.id) })}>Build schedule</button>
            <JobStatus jobId={amortJob} label="Amortization" />
          </div>
          {a && (
            <div className="mt-5 space-y-3">
              <p className="text-sm">Payoff {date(a.baseline.payoff_date)} · total interest <Money value={a.baseline.total_interest} />
                {a.with_extra && <> · with extra: payoff {date(a.with_extra.payoff_date)}, saves <Money value={a.interest_saved} /></>}
                {a.baseline.negative_amortization && <StatusBadge status="failed" label="Negative amortization" />}</p>
              <ChartFrame title="Remaining balance" chart={<LineChart labels={a.baseline.rows.map((r) => r.due.slice(0, 4))} series={[{ label: 'Balance', values: a.baseline.rows.map((r) => Number(r.balance)), color: color(0) }]} />}
                table={<table className="data-table"><thead><tr><th>#</th><th>Due</th><th className="text-right">Payment</th><th className="text-right">Interest</th><th className="text-right">Principal</th><th className="text-right">Balance</th></tr></thead>
                  <tbody>{a.baseline.rows.map((r) => <tr key={r.n}><td>{r.n}</td><td className="num">{r.due}</td><td className="text-right"><Money value={r.payment} cents /></td><td className="text-right"><Money value={r.interest} cents /></td><td className="text-right"><Money value={r.principal} cents /></td><td className="text-right"><Money value={r.balance} cents /></td></tr>)}</tbody></table>} />
            </div>
          )}
        </Card>
      </div>
      <ResourceSection type="liability_payments" title="Payments" description="Principal, interest and escrow allocation per payment; used to reconcile to statements and for deductible interest." />
    </div>
  );
}
