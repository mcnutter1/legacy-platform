import { useState } from 'react';
import { useNavigate } from 'react-router';
import { useWorkspace } from '../app/workspace';
import { PageHeader } from '../components/ui';
import { ResourceSection } from '../components/resource';
import { DuplicatesPanel } from '../components/accountCuration';

export default function Accounts() {
  const { path } = useWorkspace();
  const nav = useNavigate();
  const [showAll, setShowAll] = useState(false);
  const [dupes, setDupes] = useState(false);
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Accounts" description="Bank, brokerage, retirement, education and credit accounts. Open an account for balances, holdings, lots and transactions."
        actions={<button className="btn-secondary" aria-expanded={dupes} onClick={() => setDupes(!dupes)}>{dupes ? 'Close duplicate finder' : 'Find duplicates'}</button>} />
      {dupes && <DuplicatesPanel />}
      <ResourceSection type="accounts" filter={showAll ? {} : { visibility: 'visible' }} onOpen={(r) => nav(path(`accounts/${r.id}`))}
        description={showAll ? 'Showing every account, including hidden and ignored ones.' : 'Hidden and ignored accounts are not listed or counted.'}
        extraActions={<label className="inline-flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 min-h-0" checked={showAll} onChange={(e) => setShowAll(e.target.checked)} />Show hidden &amp; ignored</label>} />
      <ResourceSection type="source_connections" title="Data sources" description="File profiles and live connections. Read-only synchronization; nothing is ever sent to an institution."
        extraActions={<button className="btn-secondary btn-sm" onClick={() => nav(path('integrations'))}>Manage live integrations</button>} />
    </div>
  );
}
