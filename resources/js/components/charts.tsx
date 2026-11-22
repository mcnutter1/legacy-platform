import { useId, useMemo, useState, type ReactNode } from 'react';
import { money } from '../lib/format';

export interface Series {
  label: string;
  values: (number | null)[];
  color: string;
  dashed?: boolean;
}

const PALETTE = ['#1f3a33', '#a8823a', '#2f5a7a', '#7a4f2f', '#5b6b3a', '#8a3b5a', '#3a6b6b', '#6b6f6a'];
export const color = (i: number) => PALETTE[i % PALETTE.length] as string;

/** Every chart has an equivalent, keyboard-accessible table. */
export function ChartFrame({ title, chart, table, summary }: { title: string; chart: ReactNode; table: ReactNode; summary?: string }) {
  const [asTable, setAsTable] = useState(false);
  return (
    <figure className="m-0">
      <div className="mb-2 flex items-center justify-between gap-3">
        <figcaption className="text-sm font-medium text-ink-soft">{title}</figcaption>
        <button className="btn-ghost btn-sm" aria-pressed={asTable} onClick={() => setAsTable((v) => !v)}>{asTable ? 'Show chart' : 'Show table'}</button>
      </div>
      {summary && <p className="sr-only">{summary}</p>}
      {asTable ? <div className="table-wrap max-h-96 overflow-y-auto">{table}</div> : <div aria-hidden="true">{chart}</div>}
    </figure>
  );
}

function scale(min: number, max: number, a: number, b: number) {
  const d = max - min || 1;
  return (v: number) => a + ((v - min) / d) * (b - a);
}

function ticks(min: number, max: number, n = 4): number[] {
  const span = max - min || 1;
  const step = Math.pow(10, Math.floor(Math.log10(span / n)));
  const err = (span / n) / step;
  const s = step * (err >= 7.5 ? 10 : err >= 3.5 ? 5 : err >= 1.5 ? 2 : 1);
  const out: number[] = [];
  for (let v = Math.ceil(min / s) * s; v <= max + 1e-9; v += s) out.push(v);
  return out;
}

export function LineChart({ labels, series, band, height = 240, format = (v: number) => money(v, { compact: true }) }: {
  labels: (string | number)[]; series: Series[]; band?: { low: (number | null)[]; high: (number | null)[]; color?: string; label?: string }; height?: number; format?: (v: number) => string;
}) {
  const id = useId();
  const W = 720;
  const H = height;
  const P = { l: 64, r: 12, t: 12, b: 28 };
  const all = [...series.flatMap((s) => s.values), ...(band ? [...band.low, ...band.high] : [])].filter((v): v is number => v !== null && Number.isFinite(v));
  const min = Math.min(0, ...all);
  const max = Math.max(1, ...all);
  const x = scale(0, Math.max(1, labels.length - 1), P.l, W - P.r);
  const y = scale(min, max, H - P.b, P.t);
  const path = (vals: (number | null)[]) => vals.map((v, i) => (v === null ? '' : `${i === 0 || vals[i - 1] === null ? 'M' : 'L'}${x(i).toFixed(1)},${y(v).toFixed(1)}`)).join(' ');
  const every = Math.max(1, Math.ceil(labels.length / 8));
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-labelledby={`${id}-t`}>
      <title id={`${id}-t`}>{series.map((s) => s.label).join(', ')}</title>
      {ticks(min, max).map((t) => (
        <g key={t}>
          <line x1={P.l} x2={W - P.r} y1={y(t)} y2={y(t)} stroke="#e3e0d8" strokeWidth={t === 0 ? 1.5 : 1} />
          <text x={P.l - 8} y={y(t) + 4} textAnchor="end" fontSize="11" fill="#6b716c">{format(t)}</text>
        </g>
      ))}
      {labels.map((l, i) => (i % every === 0 || i === labels.length - 1 ? <text key={i} x={x(i)} y={H - 8} textAnchor="middle" fontSize="11" fill="#6b716c">{l}</text> : null))}
      {band && (
        <path
          d={`${band.high.map((v, i) => `${i === 0 ? 'M' : 'L'}${x(i)},${y(v ?? 0)}`).join(' ')} ${band.low.map((_, j) => { const i = band.low.length - 1 - j; return `L${x(i)},${y(band.low[i] ?? 0)}`; }).join(' ')} Z`}
          fill={band.color ?? '#a8823a'} opacity={0.15}
        />
      )}
      {series.map((s) => (
        <path key={s.label} d={path(s.values)} fill="none" stroke={s.color} strokeWidth={2} strokeDasharray={s.dashed ? '5 4' : undefined} strokeLinejoin="round" />
      ))}
    </svg>
  );
}

export function BarChart({ items, format = (v: number) => money(v, { compact: true }), height = 220 }: { items: { label: string; value: number; color?: string }[]; format?: (v: number) => string; height?: number }) {
  const W = 720;
  const H = height;
  const P = { l: 64, r: 8, t: 10, b: 40 };
  const vals = items.map((i) => i.value);
  const min = Math.min(0, ...vals);
  const max = Math.max(0, ...vals, 1);
  const y = scale(min, max, H - P.b, P.t);
  const bw = (W - P.l - P.r) / Math.max(1, items.length);
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img">
      {ticks(min, max).map((t) => (
        <g key={t}>
          <line x1={P.l} x2={W - P.r} y1={y(t)} y2={y(t)} stroke="#e3e0d8" />
          <text x={P.l - 8} y={y(t) + 4} textAnchor="end" fontSize="11" fill="#6b716c">{format(t)}</text>
        </g>
      ))}
      {items.map((it, i) => {
        const top = y(Math.max(0, it.value));
        const bottom = y(Math.min(0, it.value));
        return (
          <g key={it.label}>
            <rect x={P.l + i * bw + bw * 0.15} y={top} width={bw * 0.7} height={Math.max(1, bottom - top)} rx={3} fill={it.color ?? (it.value < 0 ? '#9b3b2e' : '#1f3a33')} />
            <text x={P.l + i * bw + bw / 2} y={H - 22} textAnchor="middle" fontSize="10" fill="#6b716c">{it.label.length > 14 ? `${it.label.slice(0, 13)}…` : it.label}</text>
          </g>
        );
      })}
    </svg>
  );
}

export function Donut({ items, size = 180 }: { items: { label: string; value: number }[]; size?: number }) {
  const total = items.reduce((a, b) => a + Math.max(0, b.value), 0) || 1;
  const r = size / 2 - 14;
  const c = 2 * Math.PI * r;
  let offset = 0;
  return (
    <div className="flex flex-col items-center gap-4 sm:flex-row">
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label="Allocation">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#efece4" strokeWidth={22} />
        {items.map((it, i) => {
          const frac = Math.max(0, it.value) / total;
          const el = (
            <circle key={it.label} cx={size / 2} cy={size / 2} r={r} fill="none" stroke={color(i)} strokeWidth={22}
              strokeDasharray={`${frac * c} ${c}`} strokeDashoffset={-offset * c} transform={`rotate(-90 ${size / 2} ${size / 2})`} />
          );
          offset += frac;
          return el;
        })}
      </svg>
      <ul className="space-y-1.5 text-sm">
        {items.map((it, i) => (
          <li key={it.label} className="flex items-center gap-2">
            <span className="inline-block h-3 w-3 rounded-sm" style={{ background: color(i) }} aria-hidden="true" />
            <span className="text-ink-soft">{it.label}</span>
            <span className="num ml-auto pl-4 text-muted">{((Math.max(0, it.value) / total) * 100).toFixed(1)}%</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

export function Waterfall({ steps }: { steps: { label: string; value: number; total?: boolean }[] }) {
  const items = useMemo(() => {
    let running = 0;
    return steps.map((s) => {
      const start = s.total ? 0 : running;
      const end = s.total ? s.value : running + s.value;
      running = end;
      return { ...s, start, end };
    });
  }, [steps]);
  const W = 720;
  const H = 240;
  const P = { l: 64, r: 8, t: 10, b: 48 };
  const all = items.flatMap((i) => [i.start, i.end]);
  const min = Math.min(0, ...all);
  const max = Math.max(1, ...all);
  const y = scale(min, max, H - P.b, P.t);
  const bw = (W - P.l - P.r) / Math.max(1, items.length);
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img">
      {ticks(min, max).map((t) => (
        <g key={t}>
          <line x1={P.l} x2={W - P.r} y1={y(t)} y2={y(t)} stroke="#e3e0d8" />
          <text x={P.l - 8} y={y(t) + 4} textAnchor="end" fontSize="11" fill="#6b716c">{money(t, { compact: true })}</text>
        </g>
      ))}
      {items.map((it, i) => (
        <g key={it.label}>
          <rect x={P.l + i * bw + bw * 0.15} y={y(Math.max(it.start, it.end))} width={bw * 0.7} height={Math.max(1, Math.abs(y(it.start) - y(it.end)))} rx={2}
            fill={it.total ? '#1f3a33' : it.value >= 0 ? '#2f6b4f' : '#9b3b2e'} />
          <text x={P.l + i * bw + bw / 2} y={H - 28} textAnchor="middle" fontSize="10" fill="#6b716c">{it.label.length > 16 ? `${it.label.slice(0, 15)}…` : it.label}</text>
        </g>
      ))}
    </svg>
  );
}
