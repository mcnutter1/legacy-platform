import { useState } from 'react';
import { PageHeader, Tabs } from '../components/ui';
import { ResourceSection } from '../components/resource';

export default function Family() {
  const [tab, setTab] = useState('people');
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Family" title="People & entities" description="Family members, households and relationships; LLCs, trusts and partnerships with their classifications, control roles and ownership; and your trusted contacts." />
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'people', label: 'People' }, { id: 'relationships', label: 'Relationships' }, { id: 'households', label: 'Households' },
        { id: 'entities', label: 'Entities' }, { id: 'ownership', label: 'Ownership' }, { id: 'contacts', label: 'Advisors & contacts' }]} />
      {tab === 'people' && <ResourceSection type="persons" description="People can exist without a login. Link a login under Access when someone joins." />}
      {tab === 'relationships' && <ResourceSection type="relationships" />}
      {tab === 'households' && (<><ResourceSection type="households" /><ResourceSection type="household_members" /></>)}
      {tab === 'entities' && (<>
        <ResourceSection type="legal_entities" />
        <ResourceSection type="entity_classifications" description="Tax classification history (e.g. disregarded → S corporation) with effective dates." />
        <ResourceSection type="control_roles" description="Managers, officers, trustees and signatories." />
        <ResourceSection type="legal_obligations" description="Annual reports, franchise taxes, registered-agent renewals and other compliance dates." />
      </>)}
      {tab === 'ownership' && <ResourceSection type="ownership_interests" description="Effective-dated fractional ownership of accounts, properties, assets and entities. Fractions for a subject must total 100% for any date." />}
      {tab === 'contacts' && <ResourceSection type="contacts" />}
    </div>
  );
}
