import { useNavigate } from 'react-router';
import { useWorkspace } from '../app/workspace';
import { PageHeader } from '../components/ui';
import { ResourceSection } from '../components/resource';

export default function Accounts() {
  const { path } = useWorkspace();
  const nav = useNavigate();
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Accounts" description="Bank, brokerage, retirement, education and credit accounts. Open an account for balances, holdings, lots and transactions." />
      <ResourceSection type="accounts" onOpen={(r) => nav(path(`accounts/${r.id}`))} />
      <ResourceSection type="source_connections" title="Data sources" description="File profiles and live connections. Read-only synchronization; nothing is ever sent to an institution."
        extraActions={<button className="btn-secondary btn-sm" onClick={() => nav(path('integrations'))}>Manage live integrations</button>} />
    </div>
  );
}
