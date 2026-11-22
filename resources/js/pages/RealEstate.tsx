import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useWorkspace } from '../app/workspace';
import { PageHeader } from '../components/ui';
import { ResourceSection } from '../components/resource';
import { AddPropertyDialog } from '../components/property';

export default function RealEstate() {
  const { path } = useWorkspace();
  const nav = useNavigate();
  const [adding, setAdding] = useState(false);
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Real estate" description="Homes, rentals and land with stays, use logs, expenses, improvements and depreciation. Open a property for its rental classification."
        actions={<button className="btn-primary" onClick={() => setAdding(true)}>Add by address lookup</button>} />
      <ResourceSection type="properties" onOpen={(r) => nav(path(`real-estate/${r.id}`))} />
      {adding && <AddPropertyDialog onClose={() => setAdding(false)} onCreated={(id) => nav(path(`real-estate/${id}`))} />}
    </div>
  );
}
