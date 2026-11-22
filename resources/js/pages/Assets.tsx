import { useNavigate } from 'react-router';
import { useWorkspace } from '../app/workspace';
import { PageHeader } from '../components/ui';
import { ResourceSection } from '../components/resource';

export default function Assets() {
  const { path } = useWorkspace();
  const nav = useNavigate();
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Personal & business assets" description="Vehicles, boats, jewelry, art, collectibles, business interests and private funds. Open an asset to link its loan and see equity." />
      <ResourceSection type="assets" onOpen={(r) => nav(path(`assets/${r.id}`))} />
      <ResourceSection type="valuations" filter={{ subject_type: 'asset' }} title="Asset valuations" />
    </div>
  );
}
