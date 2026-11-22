import { useState } from 'react';
import { useParams } from 'react-router';
import { useResource, useResourceList } from '../api/hooks';
import { useMetaFor, ResourceForm, ResourceSection } from '../components/resource';
import { Card, Dialog, KeyValue, Money, Notice, PageHeader, Query, Tabs } from '../components/ui';
import { date, humanize, money } from '../lib/format';
import { useWsQuery } from '../api/hooks';
import type { TaxRun } from '../api/types';

interface RentalEntry { property_id: string; name: string; classification: { category: string; activity: string; passive: boolean; fair_rental_days: number; personal_days: number; residence_threshold: number; average_stay_days: string | null; reasons: string[]; authorities: { cite: string; url: string }[] }; schedule_e: Record<string, string>; allowed?: string; suspended?: string }

export default function PropertyDetail() {
  const { id = '' } = useParams();
  const q = useResource('properties', id);
  const meta = useMetaFor('properties');
  const [tab, setTab] = useState('stays');
  const [edit, setEdit] = useState(false);
  const runs = useResourceList('tax_runs', { limit: 5 });
  const latestId = runs.data?.data.find((r) => ['estimate', 'calculated', 'reconciled', 'cpa_reviewed'].includes(String(r.status)))?.id;
  const run = useWsQuery<TaxRun>(['tax-run', latestId], `tax/runs/${latestId}`, { enabled: !!latestId && tab === 'tax' });
  return (
    <Query q={q}>
      {(p) => (
        <div className="space-y-6">
          <PageHeader eyebrow={humanize(String(p.kind))} title={String(p.name)} description={[p.city, p.state].filter(Boolean).join(', ')}
            actions={<button className="btn-secondary btn-sm" onClick={() => setEdit(true)}>Edit</button>} />
          <Card>
            <KeyValue items={[['Acquired', date(p.acquired_on as string)], ['Purchase price', money(p.purchase_price as string)], ['Land / building', `${money(p.land_value as string)} / ${money(p.building_value as string)}`],
              ['Placed in service', date(p.placed_in_service_on as string)], ['Recovery period', p.recovery_years ? `${p.recovery_years} years` : '—'], ['Status', humanize(String(p.status))]]} />
          </Card>
          <Tabs value={tab} onChange={setTab} tabs={[{ id: 'stays', label: 'Stays & bookings' }, { id: 'use', label: 'Use & participation' }, { id: 'expenses', label: 'Expenses' },
            { id: 'improvements', label: 'Improvements' }, { id: 'valuations', label: 'Valuations' }, { id: 'tax', label: 'Rental tax treatment' }, { id: 'ownership', label: 'Title' }]} />
          {tab === 'stays' && <ResourceSection type="property_stays" filter={{ property_id: id }} description="Guest stays count as fair-rental days; personal, family-free and donated stays count as personal days. Availability alone never counts." />}
          {tab === 'use' && <ResourceSection type="property_use_logs" filter={{ property_id: id }} description="Log participation hours with evidence. Material participation is evaluated separately from the vacation-home day tests." />}
          {tab === 'expenses' && <ResourceSection type="property_expenses" filter={{ property_id: id }} description="Classify each expense as a repair (deductible) or an improvement (capitalized). Undetermined items block a calculated tax status." />}
          {tab === 'improvements' && <ResourceSection type="property_improvements" filter={{ property_id: id }} />}
          {tab === 'valuations' && <ResourceSection type="valuations" filter={{ subject_type: 'property', subject_id: id }} />}
          {tab === 'ownership' && <ResourceSection type="ownership_interests" filter={{ subject_type: 'property', subject_id: id }} />}
          {tab === 'tax' && (
            <Query q={run} empty={<Notice>Run a tax calculation to see this property's classification.</Notice>}>
              {(r) => {
                const e = ((r.summary.rentals ?? []) as RentalEntry[]).find((x) => x.property_id === id);
                if (!e) return <Notice>This property was not part of the {r.tax_year} return (no rental or personal use recorded).</Notice>;
                return (
                  <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <Card title={`${r.tax_year} classification`} subtitle={`From tax run ${r.id.slice(-6)} · ${r.status}`}>
                      <KeyValue items={[['Category', humanize(e.classification.category)], ['Activity', humanize(e.classification.activity)], ['Passive', e.classification.passive ? 'Yes' : 'No'],
                        ['Fair-rental days', e.classification.fair_rental_days], ['Personal days', e.classification.personal_days], ['Residence threshold', `${e.classification.residence_threshold} days`],
                        ['Average stay', e.classification.average_stay_days ? `${e.classification.average_stay_days} days` : '—']]} />
                      <ul className="mt-4 list-disc space-y-1 pl-5 text-sm text-ink-soft">{e.classification.reasons.map((x, i) => <li key={i}>{x}</li>)}</ul>
                      <p className="mt-3 text-xs text-muted">{e.classification.authorities.map((a) => <a key={a.cite} className="link mr-3" href={a.url} target="_blank" rel="noreferrer noopener">{a.cite}</a>)}</p>
                    </Card>
                    <Card title="Schedule E">
                      <table className="data-table"><tbody>
                        {Object.entries(e.schedule_e).map(([k, v]) => <tr key={k}><td>{humanize(k)}</td><td className="text-right"><Money value={v} cents /></td></tr>)}
                        <tr><td>Allowed after passive rules</td><td className="text-right"><Money value={e.allowed} /></td></tr>
                        <tr><td>Suspended (carried forward)</td><td className="text-right"><Money value={e.suspended} /></td></tr>
                      </tbody></table>
                    </Card>
                  </div>
                );
              }}
            </Query>
          )}
          <Dialog open={edit} onClose={() => setEdit(false)} title="Edit property" wide>
            {meta && <ResourceForm meta={meta} record={p} onSaved={() => setEdit(false)} onCancel={() => setEdit(false)} />}
          </Dialog>
        </div>
      )}
    </Query>
  );
}
