import { useNavigate } from 'react-router';
import { useWorkspace } from '../app/workspace';
import { PageHeader } from '../components/ui';
import { ResourceSection } from '../components/resource';

export default function RealEstate() {
  const { path } = useWorkspace();
  const nav = useNavigate();
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Real estate" description="Homes, rentals and land with stays, use logs, expenses, improvements and depreciation. Open a property for its rental classification." />
      <ResourceSection type="properties" onOpen={(r) => nav(path(`real-estate/${r.id}`))} />
    </div>
  );
}
