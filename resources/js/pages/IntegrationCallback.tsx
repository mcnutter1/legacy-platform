import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { api } from '../api/client';
import { ErrorState, Loading } from '../components/ui';

/** OAuth return from Monarch: hand the code + state to the workspace API, then go back to Integrations. */
export default function IntegrationCallback() {
  const [params] = useSearchParams();
  const nav = useNavigate();
  const [error, setError] = useState<unknown>(null);
  const started = useRef(false);
  const state = params.get('state') ?? '';
  const [wsId, connId] = state.split('.');
  useEffect(() => {
    if (started.current) return;
    started.current = true;
    const code = params.get('code');
    if (params.get('error') || !code || !wsId || !connId) {
      setError(new Error(params.get('error_description') ?? params.get('error') ?? 'Authorization was cancelled or the link is incomplete.'));
      return;
    }
    api(`workspaces/${encodeURIComponent(wsId)}/integrations/${encodeURIComponent(connId)}/oauth/complete`, { body: { code, state } })
      .then(() => nav(`/w/${wsId}/integrations?connected=${connId}`, { replace: true }))
      .catch(setError);
  }, [params, nav, wsId, connId, state]);
  return (
    <main className="mx-auto max-w-lg p-10">
      <h1 className="mb-4 text-2xl">Connecting…</h1>
      {error ? (
        <>
          <ErrorState error={error} />
          {wsId && <Link className="link mt-4 inline-block" to={`/w/${wsId}/integrations`}>Back to Integrations</Link>}
        </>
      ) : <Loading label="Finishing authorization" />}
    </main>
  );
}
