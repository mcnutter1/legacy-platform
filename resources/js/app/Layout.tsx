import { useEffect, useState, type ReactNode } from 'react';
import { NavLink, Outlet, useLocation, useNavigate, useParams } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import type { Me } from '../api/types';
import { WorkspaceContext } from './workspace';
import { SecurityPrompts } from '../components/security';
import { ErrorState, Loading } from '../components/ui';
import { useWsQuery } from '../api/hooks';

const NAV: { group: string; items: { to: string; label: string; icon: string }[] }[] = [
  { group: '', items: [{ to: 'home', label: 'Home', icon: '◎' }, { to: 'review', label: 'Review inbox', icon: '☐' }] },
  { group: 'Wealth', items: [
    { to: 'net-worth', label: 'Net worth', icon: '◇' }, { to: 'accounts', label: 'Accounts', icon: '▤' }, { to: 'portfolio', label: 'Investments', icon: '◔' },
    { to: 'real-estate', label: 'Real estate', icon: '⌂' }, { to: 'assets', label: 'Assets', icon: '◆' }, { to: 'debts', label: 'Debts', icon: '⊖' },
    { to: 'imports', label: 'Imports', icon: '⇪' }, { to: 'integrations', label: 'Integrations', icon: '⇄' }, { to: 'year-close', label: 'Year close', icon: '▦' },
  ] },
  { group: 'Earnings & tax', items: [{ to: 'payroll', label: 'Payroll', icon: '¤' }, { to: 'business-income', label: 'Businesses & rentals', icon: '▤' }, { to: 'equity', label: 'Equity', icon: '◈' }, { to: 'tax', label: 'Tax', icon: '§' }] },
  { group: 'Planning', items: [{ to: 'planning', label: 'Retirement & goals', icon: '↗' }, { to: 'estate', label: 'Estate', icon: '⚖' }, { to: 'insurance', label: 'Insurance', icon: '☂' }] },
  { group: 'Family', items: [{ to: 'family', label: 'People & entities', icon: '⚇' }, { to: 'governance', label: 'Governance', icon: '✦' }, { to: 'vault', label: 'Vault', icon: '▣' }, { to: 'reports', label: 'Reports', icon: '⎙' }] },
  { group: 'Admin', items: [{ to: 'access', label: 'Access', icon: '⚿' }, { to: 'settings', label: 'Settings', icon: '⚙' }, { to: 'activity', label: 'Activity', icon: '≋' }, { to: 'records', label: 'All records', icon: '⋯' }] },
];

export function useMe() {
  return useQuery<Me>({ queryKey: ['me'], queryFn: () => api<{ data: Me }>('me').then((r) => r.data), staleTime: 30_000 });
}

export function WorkspaceLayout() {
  const { workspace } = useParams();
  const me = useMe();
  const qc = useQueryClient();
  const nav = useNavigate();
  const loc = useLocation();
  const [menu, setMenu] = useState(false);
  useEffect(() => setMenu(false), [loc.pathname]);
  // Switching workspace drops every cached query belonging to the previous one.
  useEffect(() => () => { qc.removeQueries({ predicate: (q) => q.queryKey[0] === workspace }); }, [workspace, qc]);
  if (me.isLoading) return <div className="p-10"><Loading /></div>;
  if (me.error || !me.data) return <div className="p-10"><ErrorState error={me.error} /></div>;
  const w = me.data.workspaces.find((x) => x.id === workspace && x.status === 'active');
  if (!w) return <div className="p-10"><ErrorState error={new Error('Workspace not found')} /></div>;
  const ctx = { id: w.id, name: w.name, role: w.role, me: me.data, path: (p: string) => `/w/${w.id}/${p.replace(/^\//, '')}` };
  return (
    <WorkspaceContext.Provider value={ctx}>
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-surface focus:px-3 focus:py-2">Skip to content</a>
      <div className="flex min-h-screen">
        <aside className={`${menu ? 'fixed inset-0 z-40 flex' : 'hidden'} w-full flex-col border-r border-line bg-surface lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-64`} aria-label="Primary">
          <div className="flex items-center justify-between gap-3 px-5 py-5">
            <div className="flex items-center gap-3">
              <span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-pine text-xs font-semibold tracking-wide text-white" aria-hidden="true">ML</span>
              <div className="min-w-0">
                <p className="font-serif text-base leading-tight">McNutt Legacy</p>
                <WorkspaceSwitcher current={w.id} me={me.data} onPick={(id) => nav(`/w/${id}/home`)} />
              </div>
            </div>
            <button className="btn-ghost btn-sm lg:hidden" onClick={() => setMenu(false)} aria-label="Close menu">✕</button>
          </div>
          <nav className="flex-1 overflow-y-auto px-3 pb-6">
            {NAV.map((g) => (
              <div key={g.group || 'top'} className="mb-4">
                {g.group && <p className="eyebrow px-3 pb-1">{g.group}</p>}
                <ul>
                  {g.items.map((i) => (
                    <li key={i.to}>
                      <NavLink to={ctx.path(i.to)} className={({ isActive }) => `flex min-h-10 items-center gap-3 rounded-lg px-3 text-sm ${isActive ? 'bg-pine-tint font-medium text-pine' : 'text-ink-soft hover:bg-sunken'}`}>
                        <span className="w-4 text-center text-muted" aria-hidden="true">{i.icon}</span>{i.label}
                      </NavLink>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </nav>
          <div className="border-t border-line px-5 py-4 text-xs text-muted">
            <p className="truncate">{me.data.user.name ?? me.data.user.email}</p>
            <form method="post" action="/auth/logout" className="mt-2" onSubmit={() => qc.clear()}>
              <input type="hidden" name="_token" value={document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''} />
              <button className="link text-xs">Sign out</button>
            </form>
          </div>
        </aside>
        <div className="flex min-w-0 flex-1 flex-col">
          <TopBar onMenu={() => setMenu(true)} />
          <main id="main" className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-8 sm:py-8">
            <Outlet />
          </main>
        </div>
      </div>
      <SecurityPrompts />
    </WorkspaceContext.Provider>
  );
}

function WorkspaceSwitcher({ current, me, onPick }: { current: string; me: Me; onPick: (id: string) => void }) {
  const active = me.workspaces.filter((w) => w.status === 'active');
  if (active.length <= 1) return <p className="truncate text-xs text-muted">{active[0]?.name}</p>;
  return (
    <select aria-label="Workspace" className="max-w-40 truncate border-0 bg-transparent p-0 text-xs text-muted" value={current} onChange={(e) => onPick(e.target.value)}>
      {active.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
    </select>
  );
}

function TopBar({ onMenu }: { onMenu: () => void }) {
  const nav = useNavigate();
  const { workspace } = useParams();
  const [q, setQ] = useState('');
  const notes = useWsQuery<{ unread: number }>(['notifications', 'count'], 'notifications', { query: { unread: true }, refetchInterval: 60_000 });
  return (
    <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-line bg-canvas/90 px-4 py-3 backdrop-blur sm:px-8">
      <button className="btn-ghost btn-sm lg:hidden" onClick={onMenu} aria-label="Open menu">☰</button>
      <form role="search" className="flex-1" onSubmit={(e) => { e.preventDefault(); if (q.trim().length > 1) nav(`/w/${workspace}/search?q=${encodeURIComponent(q.trim())}`); }}>
        <input className="input max-w-md" type="search" placeholder="Search people, accounts, documents…" aria-label="Search" value={q} onChange={(e) => setQ(e.target.value)} />
      </form>
      <NavLink to={`/w/${workspace}/notifications`} className="btn-ghost btn-sm relative" aria-label={`Notifications${notes.data?.unread ? `, ${notes.data.unread} unread` : ''}`}>
        <span aria-hidden="true">🔔</span>
        {!!notes.data?.unread && <span className="absolute -right-0.5 -top-0.5 rounded-full bg-gold px-1.5 text-[10px] font-semibold text-white">{notes.data.unread}</span>}
      </NavLink>
    </header>
  );
}

export function Section({ children }: { children: ReactNode }) {
  return <div className="space-y-6">{children}</div>;
}
