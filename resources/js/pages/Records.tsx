import { Link, useParams } from 'react-router';
import { useMeta } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { Card, PageHeader, Query } from '../components/ui';
import { ResourceSection } from '../components/resource';

export default function Records() {
  const { type } = useParams();
  const { path, me } = useWorkspace();
  const meta = useMeta();
  if (type) {
    return (
      <div className="space-y-6">
        <PageHeader eyebrow="All records" title={meta.data?.resources[type]?.label ?? type} actions={<Link className="btn-secondary btn-sm" to={path('records')}>All record types</Link>} />
        <ResourceSection type={type} />
      </div>
    );
  }
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Admin" title="All records" description="Every record type in the platform, grouped by area. Lists show only what your access allows." />
      <Query q={meta}>
        {(m) => {
          const groups: Record<string, { type: string; label: string }[]> = {};
          for (const r of Object.values(m.resources)) (groups[r.domain] ??= []).push({ type: r.type, label: r.label });
          return (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
              {Object.entries(groups).map(([d, items]) => (
                <Card key={d} title={me.domains[d] ?? (d === 'dynamic' ? 'Workflow' : d)}>
                  <ul className="space-y-1 text-sm">{items.sort((a, b) => a.label.localeCompare(b.label)).map((i) => <li key={i.type}><Link className="link" to={path(`records/${i.type}`)}>{i.label}</Link></li>)}</ul>
                </Card>
              ))}
            </div>
          );
        }}
      </Query>
    </div>
  );
}
