import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { ApiError } from '../api/client';
import { humanize, money } from '../lib/format';

export function PageHeader({ eyebrow, title, description, actions }: { eyebrow?: string; title: string; description?: ReactNode; actions?: ReactNode }) {
  return (
    <header className="mb-6 flex flex-col gap-4 sm:mb-8 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        {eyebrow && <p className="eyebrow mb-1">{eyebrow}</p>}
        <h1 className="text-2xl leading-tight sm:text-3xl">{title}</h1>
        {description && <div className="mt-2 max-w-2xl text-sm text-muted">{description}</div>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </header>
  );
}

export function Card({ title, actions, children, className = '', pad = true, subtitle }: { title?: ReactNode; subtitle?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string; pad?: boolean }) {
  return (
    <section className={`card ${className}`}>
      {(title || actions) && (
        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
          <div>
            {title && <h2 className="text-lg">{title}</h2>}
            {subtitle && <p className="mt-0.5 text-xs text-muted">{subtitle}</p>}
          </div>
          {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
      )}
      <div className={pad ? 'card-pad' : ''}>{children}</div>
    </section>
  );
}

export function Stat({ label, value, hint, tone }: { label: string; value: ReactNode; hint?: ReactNode; tone?: 'positive' | 'negative' | 'neutral' }) {
  const color = tone === 'positive' ? 'text-positive' : tone === 'negative' ? 'text-negative' : 'text-ink';
  return (
    <div className="card card-pad">
      <p className="eyebrow">{label}</p>
      <p className={`num mt-2 font-serif text-2xl sm:text-3xl ${color}`}>{value}</p>
      {hint && <p className="mt-1 text-xs text-muted">{hint}</p>}
    </div>
  );
}

export function Money({ value, cents, compact, className = '', sign }: { value: string | number | null | undefined; cents?: boolean; compact?: boolean; className?: string; sign?: boolean }) {
  const neg = value !== null && value !== undefined && Number(value) < 0;
  return <span className={`num ${neg ? 'text-negative' : ''} ${className}`}>{money(value, { cents, compact, sign })}</span>;
}

const STATUS: Record<string, string> = {
  calculated: 'bg-positive/10 text-positive', reconciled: 'bg-positive/10 text-positive', cpa_reviewed: 'bg-pine text-white', succeeded: 'bg-positive/10 text-positive',
  completed: 'bg-positive/10 text-positive', approved: 'bg-positive/10 text-positive', committed: 'bg-positive/10 text-positive', ready: 'bg-positive/10 text-positive',
  clean: 'bg-positive/10 text-positive', active: 'bg-positive/10 text-positive', accepted: 'bg-positive/10 text-positive', resolved: 'bg-positive/10 text-positive', closed: 'bg-pine-tint text-pine',
  estimate: 'bg-gold-tint text-warning', draft: 'bg-sunken text-ink-soft', pending: 'bg-sunken text-ink-soft', queued: 'bg-sunken text-ink-soft', leased: 'bg-info/10 text-info',
  previewed: 'bg-info/10 text-info', needs_review: 'bg-gold-tint text-warning', open: 'bg-gold-tint text-warning', quarantined: 'bg-gold-tint text-warning', retryable: 'bg-gold-tint text-warning',
  unsupported: 'bg-negative/10 text-negative', failed: 'bg-negative/10 text-negative', rejected: 'bg-negative/10 text-negative', timed_out: 'bg-negative/10 text-negative',
  reversed: 'bg-sunken text-muted', superseded: 'bg-sunken text-muted', cancelled: 'bg-sunken text-muted', archived: 'bg-sunken text-muted', high: 'bg-negative/10 text-negative',
};

export function StatusBadge({ status, label }: { status: string | null | undefined; label?: string }) {
  if (!status) return null;
  return <span className={`badge ${STATUS[status] ?? 'bg-sunken text-ink-soft'}`}>{label ?? humanize(status)}</span>;
}

export function Empty({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-line-strong px-6 py-12 text-center">
      <p className="font-serif text-lg">{title}</p>
      {children && <div className="mt-2 max-w-md text-sm text-muted">{children}</div>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}

export function ErrorState({ error, retry }: { error: unknown; retry?: () => void }) {
  const e = error instanceof ApiError ? error : null;
  const denied = e?.status === 403 || e?.status === 404;
  return (
    <div role="alert" className="rounded-xl border border-negative/20 bg-negative/5 px-5 py-4 text-sm">
      <p className="font-medium text-negative">{denied ? 'Not available' : 'Something went wrong'}</p>
      <p className="mt-1 text-ink-soft">{denied ? 'This information is outside your access, or it no longer exists.' : (e?.message ?? 'The request could not be completed.')}</p>
      {e?.correlationId && <p className="mt-1 text-xs text-muted">Reference {e.correlationId}</p>}
      {retry && !denied && <button className="btn-secondary btn-sm mt-3" onClick={retry}>Try again</button>}
    </div>
  );
}

export function Loading({ rows = 3, label = 'Loading' }: { rows?: number; label?: string }) {
  return (
    <div role="status" aria-label={label} className="space-y-3">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="h-5 animate-pulse rounded bg-sunken" style={{ width: `${90 - i * 12}%` }} />
      ))}
      <span className="sr-only">{label}…</span>
    </div>
  );
}

export function Query<T>({ q, children, empty }: { q: { isLoading: boolean; error: unknown; data: T | undefined; refetch: () => unknown }; children: (d: T) => ReactNode; empty?: ReactNode }) {
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} retry={() => q.refetch()} />;
  if (q.data === undefined || (Array.isArray(q.data) && q.data.length === 0 && empty)) return <>{empty ?? null}</>;
  return <>{children(q.data)}</>;
}

export function Dialog({ open, onClose, title, children, wide }: { open: boolean; onClose: () => void; title: string; children: ReactNode; wide?: boolean }) {
  const ref = useRef<HTMLDialogElement>(null);
  const id = useId();
  useEffect(() => {
    const d = ref.current;
    if (!d) return;
    if (open && !d.open) d.showModal();
    if (!open && d.open) d.close();
  }, [open]);
  return (
    <dialog
      ref={ref}
      aria-labelledby={id}
      onClose={onClose}
      onCancel={(e) => { e.preventDefault(); onClose(); }}
      className={`m-auto w-[calc(100%-2rem)] ${wide ? 'max-w-4xl' : 'max-w-lg'} rounded-2xl border border-line bg-surface p-0 text-ink shadow-xl backdrop:bg-ink/40`}
    >
      {open && (
        <div>
          <div className="flex items-center justify-between border-b border-line px-6 py-4">
            <h2 id={id} className="text-xl">{title}</h2>
            <button className="btn-ghost btn-sm" onClick={onClose} aria-label="Close">✕</button>
          </div>
          <div className="max-h-[75vh] overflow-y-auto px-6 py-5">{children}</div>
        </div>
      )}
    </dialog>
  );
}

export function Tabs({ tabs, value, onChange }: { tabs: { id: string; label: string; count?: number }[]; value: string; onChange: (id: string) => void }) {
  return (
    <div role="tablist" className="mb-5 flex gap-1 overflow-x-auto border-b border-line">
      {tabs.map((t) => (
        <button
          key={t.id}
          role="tab"
          aria-selected={value === t.id}
          onClick={() => onChange(t.id)}
          className={`-mb-px min-h-11 whitespace-nowrap border-b-2 px-3 text-sm ${value === t.id ? 'border-pine font-medium text-ink' : 'border-transparent text-muted hover:text-ink'}`}
        >
          {t.label}
          {t.count !== undefined && <span className="ml-1.5 rounded-full bg-sunken px-1.5 text-xs">{t.count}</span>}
        </button>
      ))}
    </div>
  );
}

export function FormError({ error }: { error: unknown }) {
  if (!error) return null;
  const e = error instanceof ApiError ? error : null;
  return (
    <div role="alert" className="rounded-lg bg-negative/5 px-3 py-2 text-sm text-negative">
      {e?.message ?? 'Could not save.'}
      {e?.code === 'conflict' && <span className="block text-xs">Someone else changed this record. Close and reopen to see the latest version.</span>}
    </div>
  );
}

export function Notice({ tone = 'info', children }: { tone?: 'info' | 'warning' | 'success' | 'danger'; children: ReactNode }) {
  const c = { info: 'border-info/20 bg-info/5 text-info', warning: 'border-gold/30 bg-gold-tint text-warning', success: 'border-positive/20 bg-positive/5 text-positive', danger: 'border-negative/20 bg-negative/5 text-negative' }[tone];
  return <div className={`rounded-lg border px-4 py-3 text-sm ${c}`}>{children}</div>;
}

export function KeyValue({ items }: { items: [string, ReactNode][] }) {
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
      {items.map(([k, v]) => (
        <div key={k} className="flex flex-col">
          <dt className="text-xs text-muted">{k}</dt>
          <dd className="mt-0.5">{v ?? '—'}</dd>
        </div>
      ))}
    </dl>
  );
}

export function useToggle(initial = false): [boolean, () => void, (v: boolean) => void] {
  const [v, set] = useState(initial);
  return [v, () => set((x) => !x), set];
}
