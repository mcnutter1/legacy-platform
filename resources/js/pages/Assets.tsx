import { PageHeader } from '../components/ui';
import { ResourceSection } from '../components/resource';

export default function Assets() {
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Personal & business assets" description="Vehicles, jewelry, art, collectibles, business interests and private funds. Valuations are chosen per purpose; AI never invents appraisals." />
      <ResourceSection type="assets" />
      <ResourceSection type="valuations" filter={{ subject_type: 'asset' }} title="Asset valuations" />
    </div>
  );
}
