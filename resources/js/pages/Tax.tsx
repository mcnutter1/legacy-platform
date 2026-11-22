import { Link, useNavigate } from 'react-router';
import { useWorkspace } from '../app/workspace';
import { PageHeader } from '../components/ui';
import { ResourceSection } from '../components/resource';

export default function Tax() {
  const { path } = useWorkspace();
  const nav = useNavigate();
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Tax" title="Federal & New Jersey tax" description="Deterministic, versioned rule packs with line-level trace. Results are labeled estimate, calculated, reconciled, CPA-reviewed or unsupported — never a false certainty."
        actions={<Link className="btn-secondary" to={path('tax/packs')}>Rule packs</Link>} />
      <ResourceSection type="tax_units" onOpen={(r) => nav(path(`tax/units/${r.id}`))} description="A tax unit is one filing for one year (individual or joint). Open a unit to run calculations, forecasts and strategies." />
      <ResourceSection type="tax_runs" onOpen={(r) => nav(path(`tax/runs/${r.id}`))} title="Recent runs" />
      <ResourceSection type="tax_residencies" />
    </div>
  );
}
