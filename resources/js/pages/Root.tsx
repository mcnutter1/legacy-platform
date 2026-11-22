import { useState, type FormEvent } from 'react';
import { Navigate } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '../api/client';
import { useMe } from '../app/Layout';
import { ErrorState, FormError, Loading } from '../components/ui';

/** Entry: open the first active workspace or create one (family administrators only). */
export default function Root() {
  const me = useMe();
  const qc = useQueryClient();
  const [name, setName] = useState('');
  const [err, setErr] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  if (me.isLoading) return <div className="p-10"><Loading /></div>;
  if (me.error || !me.data) return <div className="p-10"><ErrorState error={me.error} /></div>;
  const active = me.data.workspaces.filter((w) => w.status === 'active');
  if (active.length > 0) return <Navigate to={`/w/${active[0]?.id}/home`} replace />;
  const pending = me.data.workspaces.some((w) => w.status === 'pending_approval');
  const create = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try {
      await api('workspaces', { body: { name, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone } });
      await qc.invalidateQueries({ queryKey: ['me'] });
    } catch (x) {
      setErr(x instanceof ApiError ? x : new Error('Could not create workspace'));
    } finally {
      setBusy(false);
    }
  };
  return (
    <main className="mx-auto flex min-h-screen max-w-lg flex-col justify-center px-6">
      <p className="eyebrow">McNutt Legacy</p>
      <h1 className="mt-2 text-3xl">Welcome, {me.data.user.name ?? 'there'}</h1>
      {pending ? (
        <p className="mt-4 text-muted">Your invitation was accepted. An administrator needs to confirm your access before the workspace opens.</p>
      ) : me.data.user.can_create_workspaces ? (
        <form onSubmit={create} className="card card-pad mt-8 space-y-4">
          <p className="text-sm text-muted">Create the family workspace. You will become its administrator and steward of a joint scope and your private scope. Nothing is shared with anyone until you grant it.</p>
          <label className="field"><span>Workspace name</span><input value={name} onChange={(e) => setName(e.target.value)} required maxLength={120} placeholder="e.g. McNutt Family Office" /></label>
          <FormError error={err} />
          <button className="btn-primary w-full" disabled={busy}>{busy ? 'Creating…' : 'Create workspace'}</button>
        </form>
      ) : (
        <p className="mt-4 text-muted">You do not have access to a workspace yet. Ask the family administrator for an invitation link.</p>
      )}
    </main>
  );
}
