import { useParams } from 'react-router';
import { useWsQuery } from '../api/hooks';
import { Card, KeyValue, Money, Notice, PageHeader, Query, Stat, StatusBadge } from '../components/ui';
import { BarChart, ChartFrame } from '../components/charts';
import { date, humanize, money } from '../lib/format';

interface Flow { decedent: string; asset: string; recipient: string; recipient_type?: string; amount: string; mechanism: string }
interface Death { decedent: string; name: string; gross_estate: string; probate_estate: string; debts: string; administration: string; marital_deduction: string; charitable_deduction: string; taxable_estate: string; applicable_exclusion: string; federal_estate_tax: string; nj_inheritance_tax: string; liquid_probate: string; liquidity_gap: string; dsue_carried: string; basis_adjustments: { asset: string; new_basis: string; prior_basis: string }[]; flows: Flow[] }
interface Run { id: string; status: string; summary: { total_transfer_taxes: string; deaths: number; unresolved_count: number; note: string; beneficiaries: { recipient: string; amount: string }[]; trusts: { trust_id: string; name: string; funded_amount: string; distributions: { label: string; share: string; amount: string; age_schedule: { age: number; fraction: number; amount: string }[] }[] }[] } | null; deaths: Death[] | null; unresolved: { code: string; message: string }[] | null; engine_version: string | null; created_at: string }

export default function EstateRun() {
  const { run = '' } = useParams();
  const q = useWsQuery<Run>(['estate-run', run], `estate/runs/${run}`, { refetchInterval: (query) => (query.state.data?.status === 'pending' ? 2000 : false) });
  return (
    <Query q={q}>
      {(r) => (
        <div className="space-y-6">
          <PageHeader eyebrow="Estate model" title="Who receives what" description={`${date(r.created_at)} · engine ${r.engine_version ?? '—'}`} actions={<StatusBadge status={r.status} />} />
          {r.summary && <Notice>{r.summary.note}</Notice>}
          {r.summary && (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Stat label="Transfer taxes (federal + NJ)" value={money(r.summary.total_transfer_taxes)} tone={Number(r.summary.total_transfer_taxes) > 0 ? 'negative' : 'neutral'} />
              <Stat label="Deaths modeled" value={r.summary.deaths} />
              <Stat label="Items for counsel" value={r.summary.unresolved_count} tone={r.summary.unresolved_count ? 'negative' : 'positive'} />
            </div>
          )}
          {(r.unresolved ?? []).length > 0 && (
            <Card title="Issues to review with counsel">
              <ul className="space-y-2 text-sm">{(r.unresolved ?? []).map((u, i) => <li key={i} className="flex gap-3"><span className="badge bg-gold-tint text-warning">{humanize(u.code)}</span>{u.message}</li>)}</ul>
            </Card>
          )}
          {r.summary?.beneficiaries && r.summary.beneficiaries.length > 0 && (
            <Card title="Ultimate recipients">
              <ChartFrame title="Amount received" chart={<BarChart items={r.summary.beneficiaries.slice(0, 10).map((b) => ({ label: b.recipient, value: Number(b.amount) }))} />}
                table={<table className="data-table"><tbody>{r.summary.beneficiaries.map((b) => <tr key={b.recipient}><td>{b.recipient}</td><td className="text-right"><Money value={b.amount} /></td></tr>)}</tbody></table>} />
            </Card>
          )}
          {(r.deaths ?? []).map((d) => (
            <Card key={d.decedent} title={`Death of ${d.name}`}>
              <KeyValue items={[['Gross estate', <Money key="g" value={d.gross_estate} />], ['Probate estate', <Money key="p" value={d.probate_estate} />], ['Debts & administration', <Money key="d" value={Number(d.debts) + Number(d.administration)} />],
                ['Marital / charitable deduction', <><Money value={d.marital_deduction} /> / <Money value={d.charitable_deduction} /></>], ['Taxable estate', <Money key="t" value={d.taxable_estate} />],
                ['Applicable exclusion', <Money key="x" value={d.applicable_exclusion} />], ['Federal estate tax', <Money key="f" value={d.federal_estate_tax} />], ['NJ inheritance tax', <Money key="n" value={d.nj_inheritance_tax} />],
                ['Liquid probate assets', <Money key="l" value={d.liquid_probate} />], ['Liquidity gap', <Money key="lg" value={d.liquidity_gap} />], ['DSUE carried to survivor', <Money key="ds" value={d.dsue_carried} />]]} />
              <h3 className="mt-6 text-base">Transfers</h3>
              <div className="table-wrap mt-2">
                <table className="data-table"><thead><tr><th>Asset</th><th>Recipient</th><th>Mechanism</th><th className="text-right">Amount</th></tr></thead>
                  <tbody>{d.flows.map((f, i) => <tr key={i}><td>{f.asset}</td><td>{f.recipient}</td><td className="text-xs text-muted">{humanize(f.mechanism.split(':')[0] ?? '')}</td><td className="text-right"><Money value={f.amount} /></td></tr>)}</tbody></table>
              </div>
              {d.basis_adjustments.length > 0 && (
                <details className="mt-4 text-sm"><summary className="cursor-pointer text-pine">Basis adjustments ({d.basis_adjustments.length})</summary>
                  <table className="data-table mt-2"><tbody>{d.basis_adjustments.map((b, i) => <tr key={i}><td>{b.asset}</td><td className="text-right"><Money value={b.prior_basis} /> → <Money value={b.new_basis} /></td></tr>)}</tbody></table>
                </details>
              )}
            </Card>
          ))}
          {(r.summary?.trusts ?? []).map((t) => (
            <Card key={t.trust_id} title={t.name} subtitle={`Funded with ${money(t.funded_amount)}`}>
              <table className="data-table"><thead><tr><th>Beneficiary</th><th className="text-right">Share</th><th className="text-right">Amount</th><th>Schedule</th></tr></thead>
                <tbody>{t.distributions.map((d) => <tr key={d.label}><td>{d.label}</td><td className="num text-right">{(Number(d.share) * 100).toFixed(1)}%</td><td className="text-right"><Money value={d.amount} /></td><td className="text-xs">{d.age_schedule.map((s) => `age ${s.age}: ${money(s.amount)}`).join(', ') || '—'}</td></tr>)}</tbody></table>
            </Card>
          ))}
        </div>
      )}
    </Query>
  );
}
