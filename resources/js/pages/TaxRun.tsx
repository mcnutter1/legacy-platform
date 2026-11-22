import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router';
import { useCommand, useWsQuery } from '../api/hooks';
import type { TaxLine, TaxRun as Run, TraceNode } from '../api/types';
import { useWorkspace } from '../app/workspace';
import { Card, Dialog, FormError, KeyValue, Money, Notice, PageHeader, Query, Stat, StatusBadge, Tabs } from '../components/ui';
import { date, humanize, money, pct } from '../lib/format';

const STATUS_HELP: Record<string, string> = {
  estimate: 'Estimate — missing inputs, assumptions, provisional law or unapproved rule packs. Not a filing position.',
  calculated: 'Calculated from complete, approved inputs and approved rule packs. Not yet reconciled to a filed return.',
  reconciled: 'Matches the filed return line-for-line within $1.',
  cpa_reviewed: 'Reviewed and signed off by a named professional; this run is immutable.',
  unsupported: 'This return includes situations the engine does not model. Results are withheld rather than guessed.',
};

function Trace({ runId, node, onClose }: { runId: string; node: string; onClose: () => void }) {
  const q = useWsQuery<{ trace: TraceNode[] }>(['trace', runId, node], `tax/runs/${runId}/trace`, { query: { node } });
  return (
    <Dialog open onClose={onClose} title="How this number was computed" wide>
      <Query q={q}>
        {(d) => (
          <ol className="space-y-3">
            {d.trace.map((t) => (
              <li key={t.node} className="rounded-lg border border-line p-3">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <code className="text-xs text-muted">{t.jurisdiction} · {t.node}</code>
                  <Money value={t.value} cents className="font-medium" />
                </div>
                {t.rule && <p className="mt-1 text-xs text-muted">Rule: {t.rule}</p>}
                {t.note && <p className="mt-1 text-sm">{t.note}</p>}
                {Object.keys(t.inputs ?? {}).length > 0 && (
                  <p className="mt-1 text-xs text-ink-soft">Inputs: {Object.entries(t.inputs).map(([k, v]) => `${k} = ${v}`).join(' · ')}</p>
                )}
                {t.facts && t.facts.length > 0 && <p className="mt-1 text-xs text-muted">Source facts: {t.facts.join(', ')}</p>}
              </li>
            ))}
          </ol>
        )}
      </Query>
    </Dialog>
  );
}

export default function TaxRun() {
  const { run = '' } = useParams();
  const { path } = useWorkspace();
  const q = useWsQuery<Run>(['tax-run', run], `tax/runs/${run}`, { refetchInterval: (query) => (query.state.data?.status === 'pending' ? 2000 : false) });
  const [tab, setTab] = useState('summary');
  const [trace, setTrace] = useState<string | null>(null);
  const [juris, setJuris] = useState('all');
  const [reviewOpen, setReviewOpen] = useState(false);
  const [reviewer, setReviewer] = useState('');
  const [filed, setFiled] = useState<Record<string, string>>({});
  const review = useCommand<{ reviewer_name: string }>(`tax/runs/${run}/review`);
  const reconcile = useCommand<{ filed_lines: Record<string, string> }>(`tax/runs/${run}/reconcile`);
  const lines = useMemo(() => (q.data?.lines ?? []).filter((l) => juris === 'all' || l.jurisdiction === juris), [q.data, juris]);
  return (
    <Query q={q}>
      {(r) => {
        const fed = (r.summary?.federal ?? {}) as Record<string, string>;
        const nj = r.summary?.nj as Record<string, string> | undefined;
        const comb = (r.summary?.combined ?? {}) as Record<string, string>;
        return (
          <div className="space-y-6">
            <PageHeader eyebrow={`${r.tax_year} · ${humanize(r.kind)} · ${r.scenario}`} title="Tax calculation"
              description={<span>Run {r.id.slice(-6)} · {date(r.created_at)} · engine {r.engine_version ?? '—'}</span>}
              actions={<>
                <StatusBadge status={r.status} />
                <Link className="btn-secondary btn-sm" to={path(`tax/units/${r.tax_unit_id}`)}>Tax unit</Link>
                {!r.immutable && ['calculated', 'reconciled', 'estimate'].includes(r.status) && <button className="btn-secondary btn-sm" onClick={() => setReviewOpen(true)}>Record professional review</button>}
              </>} />
            {r.status === 'pending' && <Notice>Calculating…</Notice>}
            {STATUS_HELP[r.status] && <Notice tone={r.status === 'unsupported' ? 'danger' : r.status === 'estimate' ? 'warning' : 'success'}>{STATUS_HELP[r.status]}{r.summary?.status_reasons?.length ? <ul className="mt-2 list-disc pl-5">{r.summary.status_reasons.map((x) => <li key={x}>{x}</li>)}</ul> : null}</Notice>}
            {r.reviewer_name && <p className="text-sm text-muted">Reviewed by {r.reviewer_name} on {date(r.reviewed_at)}.</p>}
            {r.status !== 'unsupported' && r.status !== 'pending' && (
              <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Stat label="Total tax (federal + NJ)" value={money(comb.total_tax)} />
                <Stat label="Balance due" value={money(comb.balance_due)} tone={Number(comb.balance_due) > 0 ? 'negative' : 'neutral'} />
                <Stat label="Refund" value={money(comb.refund)} tone={Number(comb.refund) > 0 ? 'positive' : 'neutral'} />
                <Stat label="Federal marginal / effective" value={`${pct(fed.marginal_rate, 0)} / ${pct(fed.effective_rate)}`} />
              </div>
            )}
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'summary', label: 'Summary' }, { id: 'lines', label: 'Form lines', count: r.lines?.length }, { id: 'issues', label: 'Issues', count: r.issues?.length },
              { id: 'reconcile', label: 'Reconcile to filed' }, { id: 'provenance', label: 'Rule packs' }]} />
            {tab === 'summary' && (
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card title="Federal (Form 1040)">
                  <KeyValue items={[['AGI', <Money key="a" value={fed.agi} />], ['Deduction', <>{humanize(fed.deduction_type ?? '')} <Money value={fed.deduction} /></>], ['Taxable income', <Money key="t" value={fed.taxable_income} />],
                    ['Regular tax', <Money key="r" value={fed.regular_tax} />], ['AMT', <Money key="m" value={fed.amt} />], ['NIIT', <Money key="n" value={fed.niit} />], ['Additional Medicare', <Money key="am" value={fed.additional_medicare} />],
                    ['Self-employment tax', <Money key="se" value={fed.se_tax} />], ['QBI deduction', <Money key="q" value={fed.qbi_deduction} />], ['Credits', <Money key="c" value={fed.credits} />],
                    ['Total tax', <Money key="tt" value={fed.total_tax} />], ['Withholding', <Money key="w" value={fed.withholding} />], ['Estimated payments', <Money key="e" value={fed.estimated_payments} />],
                    ['Underpayment penalty (est.)', <Money key="p" value={fed.penalty_estimate} />]]} />
                </Card>
                {nj ? (
                  <Card title="New Jersey (NJ-1040)">
                    <KeyValue items={[['Gross income', <Money key="g" value={nj.gross_income} />], ['Exemptions & deductions', <Money key="d" value={nj.exemptions_deductions} />], ['Taxable income', <Money key="t" value={nj.taxable_income} />],
                      ['Tax', <Money key="x" value={nj.tax} />], ['Property tax', humanize(nj.property_tax_treatment ?? '')], ['Total tax', <Money key="tt" value={nj.total_tax} />],
                      ['Withholding', <Money key="w" value={nj.withholding} />], ['Estimated payments', <Money key="e" value={nj.estimated_payments} />], ['Balance due', <Money key="b" value={nj.balance_due} />], ['Refund', <Money key="r" value={nj.refund} />]]} />
                  </Card>
                ) : <Card title="State"><p className="text-sm text-muted">No New Jersey return in this unit.</p></Card>}
              </div>
            )}
            {tab === 'lines' && (
              <Card pad={false}>
                <div className="flex gap-2 px-5 pt-4">
                  {['all', 'US', 'NJ'].map((j) => <button key={j} className={juris === j ? 'btn-primary btn-sm' : 'btn-secondary btn-sm'} onClick={() => setJuris(j)}>{j === 'all' ? 'All' : j}</button>)}
                </div>
                <div className="table-wrap mt-3">
                  <table className="data-table">
                    <thead><tr><th>Form</th><th>Line</th><th>Description</th><th className="text-right">Amount</th><th><span className="sr-only">Trace</span></th></tr></thead>
                    <tbody>{lines.map((l: TaxLine) => (
                      <tr key={`${l.jurisdiction}${l.form}${l.line}`}>
                        <td className="text-muted">{l.jurisdiction} {l.form}</td><td className="num">{l.line}</td><td>{l.label}</td>
                        <td className="text-right"><Money value={l.value} cents /></td>
                        <td><button className="link text-xs" onClick={() => setTrace(l.node)}>Explain</button></td>
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              </Card>
            )}
            {tab === 'issues' && (
              (r.issues ?? []).length === 0 ? <Notice tone="success">No issues.</Notice> : (
                <Card pad={false}><ul className="divide-y divide-line">
                  {(r.issues ?? []).map((i, n) => (
                    <li key={n} className="flex items-start gap-3 px-5 py-3 text-sm">
                      <StatusBadge status={i.severity === 'blocking' || i.severity === 'error' ? 'failed' : i.severity === 'warning' ? 'needs_review' : 'draft'} label={humanize(i.severity)} />
                      <div><p>{i.message}</p><p className="text-xs text-muted">{i.jurisdiction ?? ''} {i.code}</p></div>
                    </li>
                  ))}
                </ul></Card>
              )
            )}
            {tab === 'reconcile' && (
              <Card title="Compare to the filed return" subtitle="Enter amounts from the signed return. Neither the filed figures nor this run are overwritten.">
                <div className="table-wrap">
                  <table className="data-table">
                    <thead><tr><th>Line</th><th className="text-right">Calculated</th><th>Filed</th><th className="text-right">Difference</th></tr></thead>
                    <tbody>{(r.lines ?? []).filter((l) => /total|taxable|agi|tax$|refund|owe|balance/i.test(l.label)).slice(0, 20).map((l) => {
                      const key = `${l.jurisdiction}|${l.form}|${l.line}`;
                      const rec = r.reconciliation?.lines.find((x) => x.line === key);
                      return (
                        <tr key={key}>
                          <td>{l.jurisdiction} {l.form} {l.line} <span className="text-muted">{l.label}</span></td>
                          <td className="text-right"><Money value={l.value} /></td>
                          <td><input aria-label={`Filed ${l.label}`} className="input min-h-9 w-32" inputMode="decimal" value={filed[key] ?? rec?.filed ?? ''} onChange={(e) => setFiled({ ...filed, [key]: e.target.value })} /></td>
                          <td className={`num text-right ${rec && !rec.match ? 'text-negative' : ''}`}>{rec?.difference ?? ''}</td>
                        </tr>
                      );
                    })}</tbody>
                  </table>
                </div>
                <FormError error={reconcile.error} />
                <div className="mt-4 flex items-center gap-3">
                  <button className="btn-primary" disabled={!Object.keys(filed).length} onClick={() => reconcile.mutate({ filed_lines: filed })}>Compare</button>
                  {r.reconciliation && <StatusBadge status={r.reconciliation.all_match ? 'reconciled' : 'needs_review'} label={r.reconciliation.all_match ? 'All lines match' : 'Differences found'} />}
                </div>
              </Card>
            )}
            {tab === 'provenance' && (
              <Card title="Provenance">
                <KeyValue items={[['Engine', r.engine_version ?? '—'], ['Input hash', <code key="h" className="text-xs">{r.input_hash?.slice(0, 16)}</code>],
                  ...Object.entries(r.rule_packs ?? {}).map(([j, p]) => [`${j} rule pack`, <span key={j}>{p.version} · {p.approved ? 'approved' : 'not approved'} · {p.legislation_status} · <code className="text-xs">{p.hash?.slice(0, 12)}</code></span>] as [string, React.ReactNode])]} />
              </Card>
            )}
            {trace && <Trace runId={r.id} node={trace} onClose={() => setTrace(null)} />}
            <Dialog open={reviewOpen} onClose={() => setReviewOpen(false)} title="Record professional review">
              <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); review.mutate({ reviewer_name: reviewer }, { onSuccess: () => setReviewOpen(false) }); }}>
                <p className="text-sm text-muted">Records a named sign-off and makes this run immutable. Requires a recent identity confirmation.</p>
                <label className="field"><span>Reviewer full name and credential</span><input value={reviewer} onChange={(e) => setReviewer(e.target.value)} required minLength={3} placeholder="Jane Smith, CPA" /></label>
                <FormError error={review.error} />
                <div className="flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={() => setReviewOpen(false)}>Cancel</button><button className="btn-primary">Sign off</button></div>
              </form>
            </Dialog>
          </div>
        );
      }}
    </Query>
  );
}
