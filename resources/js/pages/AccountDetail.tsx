import { useState } from 'react';
import { useParams } from 'react-router';
import { useResource, useWsQuery } from '../api/hooks';
import { useMetaFor, ResourceForm, ResourceSection } from '../components/resource';
import { Card, Dialog, KeyValue, Notice, PageHeader, Query, StatusBadge, Tabs } from '../components/ui';
import { humanize, pct } from '../lib/format';
import { AccountSources } from '../components/sourcesPanel';
import { recordSources, SourceIcons } from '../components/sources';

interface Perf { status: string; message?: string; start_value?: number; end_value?: number; net_flows?: number; time_weighted?: { cumulative: number; annualized: number | null }; money_weighted?: { irr_annualized: number }; explanation?: string }

export default function AccountDetail() {
  const { id = '' } = useParams();
  const q = useResource('accounts', id);
  const meta = useMetaFor('accounts');
  const [tab, setTab] = useState('activity');
  const [edit, setEdit] = useState(false);
  const perf = useWsQuery<Perf>(['perf', id], `portfolio/performance/${id}`, { enabled: tab === 'performance' });
  return (
    <Query q={q}>
      {(a) => (
        <div className="space-y-6">
          <PageHeader eyebrow={`${humanize(String(a.kind))} · ${a.institution ?? ''}`} title={String(a.name)}
            description={<span>Tax bucket: {humanize(String(a.tax_bucket))} · Liquidity: {humanize(String(a.liquidity))} {a.mask ? `· ••${a.mask}` : ''}</span>}
            actions={<><SourceIcons sources={recordSources(a)} size={22} /><StatusBadge status={String(a.status)} /><button className="btn-secondary btn-sm" onClick={() => setEdit(true)}>Edit</button></>} />
          <Tabs value={tab} onChange={setTab} tabs={[{ id: 'activity', label: 'Transactions' }, { id: 'balances', label: 'Balances' }, { id: 'holdings', label: 'Holdings' },
            { id: 'sources', label: 'Sources' },
            { id: 'lots', label: 'Tax lots' }, { id: 'performance', label: 'Performance' }, { id: 'beneficiaries', label: 'Beneficiaries' }, { id: 'ownership', label: 'Ownership' }]} />
          {tab === 'sources' && <AccountSources accountId={id} />}
          {tab === 'activity' && <ResourceSection type="transactions" filter={{ account_id: id }} />}
          {tab === 'balances' && <ResourceSection type="balance_snapshots" filter={{ account_id: id }} description="Statement balances are the reconciliation anchor for imports." />}
          {tab === 'holdings' && <ResourceSection type="holdings" filter={{ account_id: id }} />}
          {tab === 'lots' && <ResourceSection type="lots" filter={{ account_id: id }} description="Lot provenance is preserved; custodian statements take precedence in basis disputes." />}
          {tab === 'beneficiaries' && <ResourceSection type="beneficiary_designations" filter={{ subject_type: 'account', subject_id: id }} />}
          {tab === 'ownership' && <ResourceSection type="ownership_interests" filter={{ subject_type: 'account', subject_id: id }} />}
          {tab === 'performance' && (
            <Card title="Performance (trailing 12 months)">
              <Query q={perf}>
                {(p) => p.status !== 'ok' ? <Notice tone="warning">{p.message}</Notice> : (
                  <div className="space-y-4">
                    <KeyValue items={[['Time-weighted return', pct(p.time_weighted?.cumulative)], ['Annualized (TWR)', pct(p.time_weighted?.annualized)],
                      ['Money-weighted (IRR)', pct(p.money_weighted?.irr_annualized)], ['Net contributions', String(p.net_flows)]]} />
                    <p className="text-xs text-muted">{p.explanation}</p>
                  </div>
                )}
              </Query>
            </Card>
          )}
          <Dialog open={edit} onClose={() => setEdit(false)} title="Edit account" wide>
            {meta && <ResourceForm meta={meta} record={a} onSaved={() => setEdit(false)} onCancel={() => setEdit(false)} />}
          </Dialog>
        </div>
      )}
    </Query>
  );
}
