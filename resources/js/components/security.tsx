import { useEffect, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, onApiError, ApiError } from '../api/client';
import { useJob } from '../api/hooks';
import type { Job, Me } from '../api/types';
import { Dialog, Notice, StatusBadge } from './ui';
import { QrCode } from './qr';
import { humanize } from '../lib/format';

/** Scan-a-QR authenticator enrollment; confirming also counts as a fresh step-up. */
export function AuthenticatorSetup({ replacing, onDone, onCancel }: { replacing?: boolean; onDone: () => void; onCancel?: () => void }) {
  const qc = useQueryClient();
  const [enroll, setEnroll] = useState<{ secret: string; otpauth_uri: string } | null>(null);
  const [code, setCode] = useState('');
  const [err, setErr] = useState<unknown>(null);
  const start = async () => { setErr(null); try { setEnroll((await api<{ data: { secret: string; otpauth_uri: string } }>('stepup/enroll', { body: {} })).data); } catch (e) { setErr(e); } };
  const confirm = async (c: string) => {
    setErr(null);
    try { await api('stepup/verify', { body: { code: c } }); setEnroll(null); setCode(''); void qc.invalidateQueries({ queryKey: ['me'] }); onDone(); } catch (e) { setErr(e); setCode(''); }
  };
  if (!enroll) {
    return (
      <div className="space-y-3">
        <button type="button" className="btn-primary" onClick={start}>{replacing ? 'Replace authenticator' : 'Set up authenticator'}</button>
        {err ? <p className="text-sm text-negative" role="alert">{err instanceof ApiError ? err.message : 'Could not start setup.'}</p> : null}
      </div>
    );
  }
  return (
    <div className="grid gap-6 sm:grid-cols-[auto_1fr]">
      <div className="flex flex-col items-center gap-2">
        <QrCode value={enroll.otpauth_uri} label="QR code to add McNutt Legacy to your authenticator app" />
        <a className="link text-sm sm:hidden" href={enroll.otpauth_uri}>On this phone? Tap to add</a>
      </div>
      <div className="space-y-4">
        <ol className="list-decimal space-y-1.5 pl-5 text-sm">
          <li>Open your authenticator app (1Password, Google Authenticator, Microsoft Authenticator, Authy…).</li>
          <li>Tap <strong>Add</strong> / <strong>+</strong> and <strong>scan the QR code</strong>.</li>
          <li>Enter the 6-digit code it shows for <em>McNutt Legacy</em>.</li>
        </ol>
        <div className="flex flex-wrap items-end gap-3">
          <label className="field w-40"><span>6-digit code</span>
            <input inputMode="numeric" autoComplete="one-time-code" autoFocus maxLength={6} value={code}
              onChange={(e) => { const v = e.target.value.replace(/\D/g, ''); setCode(v); if (v.length === 6) void confirm(v); }} /></label>
          <button type="button" className="btn-primary" disabled={code.length !== 6} onClick={() => void confirm(code)}>Confirm</button>
          <button type="button" className="btn-ghost" onClick={() => { setEnroll(null); setCode(''); onCancel?.(); }}>Cancel</button>
        </div>
        {err ? <p className="text-sm text-negative" role="alert">{err instanceof ApiError ? err.message : 'Verification failed.'}</p> : null}
        <details className="text-sm">
          <summary className="cursor-pointer text-muted">Can't scan? Enter the setup key instead</summary>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <code className="rounded bg-sunken p-2 font-mono tracking-wider">{enroll.secret.replace(/(.{4})/g, '$1 ').trim()}</code>
            <button type="button" className="btn-secondary btn-sm" onClick={() => void navigator.clipboard?.writeText(enroll.secret)}>Copy</button>
          </div>
          <p className="mt-1 text-xs text-muted">Time-based (TOTP), 6 digits, every 30 seconds.</p>
        </details>
      </div>
    </div>
  );
}

/** Listens for step_up_required / session_ended errors and prompts globally. */
export function SecurityPrompts() {
  const [stepup, setStepup] = useState(false);
  const [ended, setEnded] = useState(false);
  const [code, setCode] = useState('');
  const [err, setErr] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const qc = useQueryClient();
  const enrolled = qc.getQueryData<Me>(['me'])?.user.stepup_enrolled ?? true;
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
      <Dialog open={stepup} onClose={() => setStepup(false)} title={enrolled ? "Confirm it's you" : 'Set up your authenticator'} wide={!enrolled && !done}>
        {done ? (
          <div className="space-y-4">
            <Notice tone="success">Verified for the next 10 minutes. Repeat the action to continue.</Notice>
            <div className="flex justify-end"><button className="btn-primary" onClick={() => setStepup(false)}>Continue</button></div>
          </div>
        ) : !enrolled ? (
          <div className="space-y-4">
            <p className="text-sm text-muted">This action needs a code from an authenticator app. Scan the QR code once; after that you just type the 6-digit code.</p>
            <AuthenticatorSetup onDone={() => setDone(true)} />
          </div>
        ) : (
          <form onSubmit={submit} className="space-y-4">
            <p className="text-sm text-muted">This action changes access, approvals or exports. Enter the 6-digit code from your authenticator app.</p>
            <label className="field"><span>Authenticator code</span>
              <input inputMode="numeric" autoComplete="one-time-code" pattern="\d{6}" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} required autoFocus />
            </label>
            {err && <p className="text-sm text-negative" role="alert">{err}</p>}
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
