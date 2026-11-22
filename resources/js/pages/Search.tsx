import { Link, useSearchParams } from 'react-router';
import { useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { Card, Empty, PageHeader, Query } from '../components/ui';
import { recordPath } from '../lib/paths';

interface Hit { type: string; label: string; id: string; title: string }

export default function Search() {
  const [params] = useSearchParams();
  const q = params.get('q') ?? '';
  const { path } = useWorkspace();
  const res = useWsQuery<Hit[]>(['search', q], 'search', { query: { q }, enabled: q.length > 1 });
  return (
    <div className="space-y-6">
      <PageHeader title={`Search: “${q}”`} description="Results include only records you are allowed to see." />
      <Card pad={false}>
        <Query q={res} empty={<div className="p-6"><Empty title="No matches" /></div>}>
          {(hits) => hits.length === 0 ? <div className="p-6"><Empty title="No matches">Try a name, institution, merchant or document title.</Empty></div> : (
            <ul className="divide-y divide-line">
              {hits.map((h) => (
                <li key={`${h.type}${h.id}`}>
                  <Link to={path(recordPath(h.type, h.id))} className="flex items-center justify-between gap-3 px-5 py-3 hover:bg-sunken">
                    <span>{h.title}</span><span className="text-xs text-muted">{h.label}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Query>
      </Card>
    </div>
  );
}
