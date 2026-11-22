/** Display formatting only. Arithmetic on money stays on the server; these helpers never feed values back. */

const moneyCache = new Map<string, Intl.NumberFormat>();

export function money(value: string | number | null | undefined, opts: { currency?: string; cents?: boolean; compact?: boolean; sign?: boolean } = {}): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = typeof value === 'number' ? value : Number(value);
  if (!Number.isFinite(n)) return '—';
  const key = `${opts.currency ?? 'USD'}|${opts.cents ? 1 : 0}|${opts.compact ? 1 : 0}|${opts.sign ? 1 : 0}`;
  let f = moneyCache.get(key);
  if (!f) {
    f = new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: opts.currency ?? 'USD',
      notation: opts.compact ? 'compact' : 'standard',
      minimumFractionDigits: opts.cents ? 2 : 0,
      maximumFractionDigits: opts.cents ? 2 : opts.compact ? 1 : 0,
      signDisplay: opts.sign ? 'exceptZero' : 'auto',
    });
    moneyCache.set(key, f);
  }
  return f.format(n);
}

export function num(value: string | number | null | undefined, digits = 2): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value);
  return Number.isFinite(n) ? n.toLocaleString('en-US', { maximumFractionDigits: digits }) : '—';
}

export function pct(value: string | number | null | undefined, digits = 1): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value);
  return Number.isFinite(n) ? `${(n * 100).toFixed(digits)}%` : '—';
}

export function date(value: string | null | undefined, style: 'short' | 'long' = 'short'): string {
  if (!value) return '—';
  const d = /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00`) : new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('en-US', style === 'long' ? { year: 'numeric', month: 'long', day: 'numeric' } : { year: 'numeric', month: 'short', day: 'numeric' });
}

export function relative(value: string | null | undefined): string {
  if (!value) return '—';
  const diff = (Date.now() - new Date(value).getTime()) / 1000;
  if (diff < 60) return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)} h ago`;
  if (diff < 86400 * 30) return `${Math.floor(diff / 86400)} d ago`;
  return date(value);
}

export function humanize(s: string | null | undefined): string {
  if (!s) return '';
  return s.replace(/_/g, ' ').replace(/\b(\w)/, (c) => c.toUpperCase()).replace(/\bnj\b/gi, 'NJ').replace(/\bus\b/g, 'US').replace(/\b(\d+)k\b/i, '$1(k)');
}

export function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function isNegative(v: string | number | null | undefined): boolean {
  return v !== null && v !== undefined && Number(v) < 0;
}
