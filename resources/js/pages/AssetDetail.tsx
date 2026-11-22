import { useState } from 'react';
import { useParams } from 'react-router';
import { useResource } from '../api/hooks';
import { ResourceForm, ResourceSection, useMetaFor } from '../components/resource';
import { Card, Dialog, KeyValue, PageHeader, Query, Tabs } from '../components/ui';
import { FinancingPanel } from '../components/property';
import { TitlePanel } from '../components/title';
import { date, humanize, money } from '../lib/format';

export default function AssetDetail() {
  const { id = '' } = useParams();
  const q = useResource('assets', id);
  const meta = useMetaFor('assets');
  const [tab, setTab] = useState('financing');
  const [edit, setEdit] = useState(false);
  return (
    <Query q={q}>
      {(a) => (
        <div className="space-y-6">
          <PageHeader eyebrow={humanize(String(a.kind))} title={String(a.name)}
            description={[a.model_year, a.make, a.model].filter(Boolean).join(' ') || undefined}
            actions={<button className="btn-secondary btn-sm" onClick={() => setEdit(true)}>Edit</button>} />
          <Card>
            <KeyValue items={[['Acquired', date(a.acquired_on as string)], ['Cost', money(a.cost as string)], ['Make / model', [a.make, a.model].filter(Boolean).join(' ') || '—'],
              ['Model year', String(a.model_year ?? '—')], ['Registration', a.registration_state ? `${a.registration_state}${a.registration_expires_on ? ` · expires ${date(a.registration_expires_on as string)}` : ''}` : '—'],
              ['Status', humanize(String(a.status ?? '—'))]]} />
          </Card>
          <Tabs value={tab} onChange={setTab} tabs={[{ id: 'financing', label: 'Loans & equity' }, { id: 'valuations', label: 'Valuations' }, { id: 'ownership', label: 'Owners' }]} />
          {tab === 'financing' && <FinancingPanel type="asset" id={id} />}
          {tab === 'valuations' && <ResourceSection type="valuations" filter={{ subject_type: 'asset', subject_id: id }} defaults={{ subject_type: 'asset', subject_id: id }} hidden={['subject_type', 'subject_id']} />}
          {tab === 'ownership' && <TitlePanel type="asset" id={id} />}
          <Dialog open={edit} onClose={() => setEdit(false)} title="Edit asset" wide>
            {meta && <ResourceForm meta={meta} record={a} onSaved={() => setEdit(false)} onCancel={() => setEdit(false)} />}
          </Dialog>
        </div>
      )}
    </Query>
  );
}
