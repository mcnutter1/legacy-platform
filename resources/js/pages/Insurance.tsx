import { useState } from 'react';
import { useCommand, useResourceList, useWsQuery } from '../api/hooks';
import { Card, FormError, KeyValue, Money, Notice, PageHeader, Query, Stat, StatusBadge, Tabs } from '../components/ui';
import { ResourceSection } from '../components/resource';
import { humanize, money } from '../lib/format';

interface Analysis { policies: number; annual_premiums: string; life_death_benefit: string; net_surrender_value: string; flags: { severity: string; code: string; message: string }[]; note: string }
interface Loss { loss: string; deductible: string; applicable_limit: string | null; primary_recovery: string; excess_recovery: string; uninsured: string; valuation_basis: string | null; exclusions_to_review: string[]; unknown_terms: string[]; note: string }
interface QuoteCompare { baseline: Record<string, unknown>; quotes: { quote_id: string; carrier: string; premium: string; carrier_rating: string | null; differences: { field: string; kind: string; message: string }[] }[]; note: string }

export default function Insurance() {
  const a = useWsQuery<Analysis>(['insurance-analysis'], 'insurance/analysis');
  const [tab, setTab] = useState('policies');
  const coverages = useResourceList('policy_coverages', { limit: 200 }, tab === 'loss');
  const cases = useResourceList('insurance_shopping_cases', { limit: 50 }, tab === 'shopping');
  const [cov, setCov] = useState('');
  const [amount, setAmount] = useState('');
  const [caseId, setCaseId] = useState('');
  const loss = useCommand<{ coverage_id: string; loss_amount: string }, Loss>('insurance/loss-summary');
  const cmp = useWsQuery<QuoteCompare>(['quote-compare', caseId], `insurance/cases/${caseId}/compare`, { enabled: !!caseId });
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Planning" title="Insurance" description="Policies, coverages and limits by insured item; gaps, overlaps and umbrella requirements; loss scenarios and quote comparison. Coverage is never bound from here." />
      <Query q={a}>
        {(d) => (
          <>
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              <Stat label="Policies" value={d.policies} />
              <Stat label="Annual premiums" value={money(d.annual_premiums)} />
              <Stat label="Life death benefit" value={money(d.life_death_benefit, { compact: true })} />
              <Stat label="Net surrender value" value={money(d.net_surrender_value, { compact: true })} />
            </div>
            {d.flags.length > 0 && (
              <Card title="Review">
                <ul className="space-y-2 text-sm">{d.flags.map((f, i) => <li key={i} className="flex items-start gap-3"><StatusBadge status={f.severity === 'high' ? 'failed' : f.severity === 'normal' ? 'needs_review' : 'draft'} label={humanize(f.code)} />{f.message}</li>)}</ul>
                <p className="mt-3 text-xs text-muted">{d.note}</p>
              </Card>
            )}
          </>
        )}
      </Query>
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'policies', label: 'Policies' }, { id: 'coverages', label: 'Coverages' }, { id: 'claims', label: 'Claims' }, { id: 'loss', label: 'Loss scenario' }, { id: 'shopping', label: 'Shopping' }]} />
      {tab === 'policies' && <ResourceSection type="insurance_policies" />}
      {tab === 'coverages' && <ResourceSection type="policy_coverages" description="One row per coverage with its own limit, sublimit, deductible and valuation basis." />}
      {tab === 'claims' && <ResourceSection type="insurance_claims" />}
      {tab === 'loss' && (
        <Card title="What would a loss cost us?">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <label className="field sm:col-span-2"><span>Coverage</span><select value={cov} onChange={(e) => setCov(e.target.value)}><option value="">— Select —</option>{(coverages.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c._title}</option>)}</select></label>
            <label className="field"><span>Loss amount</span><input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} /></label>
          </div>
          <div className="mt-4"><button className="btn-primary" disabled={!cov || !amount} onClick={() => loss.mutate({ coverage_id: cov, loss_amount: amount })}>Estimate</button></div>
          <FormError error={loss.error} />
          {loss.data && (
            <div className="mt-5 space-y-3">
              <KeyValue items={[['Loss', <Money key="l" value={loss.data.loss} />], ['Deductible', <Money key="d" value={loss.data.deductible} />], ['Applicable limit', loss.data.applicable_limit ? <Money value={loss.data.applicable_limit} /> : 'Unknown'],
                ['Primary recovery', <Money key="p" value={loss.data.primary_recovery} />], ['Umbrella / excess', <Money key="e" value={loss.data.excess_recovery} />], ['Out of pocket', <Money key="u" value={loss.data.uninsured} />],
                ['Valuation basis', loss.data.valuation_basis ?? 'Unknown']]} />
              {(loss.data.unknown_terms.length > 0 || loss.data.exclusions_to_review.length > 0) && <Notice tone="warning">Unknown: {loss.data.unknown_terms.join(', ') || 'none'}. Exclusions to review: {loss.data.exclusions_to_review.join(', ') || 'none'}.</Notice>}
              <p className="text-xs text-muted">{loss.data.note}</p>
            </div>
          )}
        </Card>
      )}
      {tab === 'shopping' && (
        <div className="space-y-6">
          <ResourceSection type="insurance_shopping_cases" />
          <ResourceSection type="insurance_quotes" description="Enter limits and deductibles as JSON maps (e.g. {&quot;dwelling&quot;: 1200000}) so quotes normalize against the baseline." />
          <Card title="Compare quotes">
            <label className="field max-w-md"><span>Case</span><select value={caseId} onChange={(e) => setCaseId(e.target.value)}><option value="">— Select —</option>{(cases.data?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c._title}</option>)}</select></label>
            {caseId && (
              <Query q={cmp}>
                {(c) => (
                  <div className="table-wrap mt-4">
                    <table className="data-table"><thead><tr><th>Carrier</th><th className="text-right">Premium</th><th>Rating</th><th>Differences vs. baseline</th></tr></thead>
                      <tbody>{c.quotes.map((x) => <tr key={x.quote_id}><td>{x.carrier}</td><td className="text-right"><Money value={x.premium} /></td><td>{x.carrier_rating ?? '—'}</td>
                        <td className="text-xs">{x.differences.length ? x.differences.map((d) => d.message).join(' ') : <span className="text-positive">Matches or exceeds baseline</span>}</td></tr>)}</tbody></table>
                    <p className="mt-2 text-xs text-muted">{c.note}</p>
                  </div>
                )}
              </Query>
            )}
          </Card>
        </div>
      )}
    </div>
  );
}
