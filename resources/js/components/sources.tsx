import type { AnyRecord } from '../api/types';
import { date, humanize } from '../lib/format';

export interface SourceBadge { provider: string; connection_id: string | null; label: string | null; mode: string; authoritative: boolean; last_seen_at: string | null }

const STYLE: Record<string, { bg: string; fg: string; glyph: string; name: string }> = {
  monarch: { bg: '#FF692D', fg: '#fff', glyph: 'M', name: 'Monarch Money' },
  snowball: { bg: '#2E6BE6', fg: '#fff', glyph: 'S', name: 'Snowball Analytics' },
  manual: { bg: 'transparent', fg: 'currentColor', glyph: '✎', name: 'Entered manually' },
  invoices: { bg: '#5B6B7A', fg: '#fff', glyph: 'I', name: 'Invoices' },
  property_manager: { bg: '#5B6B7A', fg: '#fff', glyph: 'P', name: 'Property manager' },
  generic_csv: { bg: '#5B6B7A', fg: '#fff', glyph: 'C', name: 'CSV import' },
};

export function providerName(p: string): string {
  return STYLE[p]?.name ?? humanize(p);
}

export function SourceIcon({ provider, size = 20, dim, title }: { provider: string; size?: number; dim?: boolean; title?: string }) {
  const s = STYLE[provider] ?? { bg: '#5B6B7A', fg: '#fff', glyph: provider.slice(0, 1).toUpperCase(), name: humanize(provider) };
  return (
    <span title={title ?? s.name} aria-label={title ?? s.name} role="img"
      className={`inline-flex shrink-0 select-none items-center justify-center rounded-full font-semibold leading-none ${provider === 'manual' ? 'border border-line text-muted' : ''} ${dim ? 'opacity-45' : ''}`}
      style={{ width: size, height: size, fontSize: size * 0.55, background: s.bg, color: s.fg }}>
      {s.glyph}
    </span>
  );
}

/** Axonius-style stack of the sources that reported a record; the authoritative one is outlined. */
export function SourceIcons({ sources, size = 20 }: { sources: SourceBadge[] | undefined; size?: number }) {
  if (!sources?.length) return null;
  return (
    <span className="inline-flex items-center gap-1">
      {sources.map((s, i) => {
        const title = [s.label ?? providerName(s.provider), s.mode === 'mcp' ? 'live sync' : s.mode === 'file' ? 'file import' : null,
          sources.length > 1 ? (s.authoritative ? 'authoritative' : 'also reports this') : null, s.last_seen_at ? `seen ${date(s.last_seen_at)}` : null].filter(Boolean).join(' · ');
        return (
          <span key={`${s.provider}-${s.connection_id ?? i}`} className={`rounded-full ${s.authoritative && sources.length > 1 ? 'ring-2 ring-pine ring-offset-1 ring-offset-surface' : ''}`}>
            <SourceIcon provider={s.provider} size={size} dim={!s.authoritative && sources.length > 1} title={title} />
          </span>
        );
      })}
    </span>
  );
}

export function recordSources(r: AnyRecord): SourceBadge[] | undefined {
  return (r as { _sources?: SourceBadge[] })._sources;
}
