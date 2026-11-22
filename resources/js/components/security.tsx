import { useEffect, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, onApiError, ApiError } from '../api/client';
import { useJob } from '../api/hooks';
import type { Job } from '../api/types';
import { Dialog, Notice, StatusBadge } from './ui';
import { humanize } from '../lib/format';

/** Listens for step_up_required / session_ended errors and prompts globally. */
export function SecurityPrompts() {
  const [stepup, setStepup] = useState(false);
  const [ended, setEnded] = useState(false);
  const [code, setCode] = useState('');
  const [err, setErr] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const qc = useQueryClient();
  useEffect(() => onApiError((e) => {
    if (e.code === 'step_up_required') { setStepup(true); setDone(false); setCode(''); setErr(null); }
    if (e.status === 401 || e.code === 'csrf') setEnded(true);
  }), []);
  const submit = async (ev: FormEvent) => {
    ev.preventDefault();
    try {
      await api('stepup/verify', { body: { code } });
      setDone(true);
      void qc.invalidateQueries({ queryKey: ['me'] });
    } catch (e) {
      setErr(e instanceof ApiError ? e.message : 'Verification failed.');
    }
  };
  return (
    <>
      <Dialog open={stepup} onClose={() => setStepup(false)} title="Confirm it's you">
        {done ? (
          <div className="space-y-4">
            <Notice tone="success">Verified for the next 10 minutes. Repeat the action to continue.</Notice>
            <div className="flex justify-end"><button className="btn-primary" onClick={() => setStepup(false)}>Continue</button></div>
          </div>
        ) : (
          <form onSubmit={submit} className="space-y-4">
            <p className="text-sm text-muted">This action changes access, approvals or exports. Enter the 6-digit code from your authenticator app.</p>
            <label className="field"><span>Authenticator code</span>
              <input inputMode="numeric" autoComplete="one-time-code" pattern="\d{6}" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} required autoFocus />
            </label>
            {err && <p className="text-sm text-negative" role="alert">{err}</p>}
            <p className="text-xs text-muted">No authenticator yet? Set one up under Settings → Security.</p>
            <div className="flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={() => setStepup(false)}>Cancel</button><button className="btn-primary">Verify</button></div>
          </form>
        )}
      </Dialog>
      <Dialog open={ended} onClose={() => setEnded(false)} title="Session ended">
        <p className="text-sm text-muted">For your security you have been signed out. Sign in again to continue.</p>
        <div className="mt-5 flex justify-end"><a className="btn-primary" href="/auth/login">Sign in</a></div>
      </Dialog>
    </>
  );
}

export function JobStatus({ jobId, onDone, label }: { jobId: string | null | undefined; onDone?: (j: Job) => void; label?: string }) {
  const q = useJob(jobId);
  const status = q.data?.status;
  useEffect(() => {
    if (q.data && status && ['succeeded', 'failed', 'timed_out', 'cancelled'].includes(status)) onDone?.(q.data);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status]);
  if (!jobId) return null;
  return (
    <div className="flex items-center gap-3 rounded-lg border border-line bg-canvas px-3 py-2 text-sm" role="status" aria-live="polite">
      {status && !['succeeded', 'failed', 'timed_out', 'cancelled'].includes(status) && <span className="h-3 w-3 animate-spin rounded-full border-2 border-pine border-t-transparent" aria-hidden="true" />}
      <span>{label ?? humanize(q.data?.type ?? 'job')}</span>
      <StatusBadge status={status ?? 'queued'} />
      {q.data?.error && <span className="text-xs text-negative">{q.data.error}</span>}
    </div>
  );
}
