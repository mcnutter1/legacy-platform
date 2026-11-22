/** Same-origin JSON client: CSRF header, Idempotency-Key on mutations, If-Match on updates, structured errors. */

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: Record<string, unknown> | null = null,
    public readonly correlationId: string | null = null,
  ) {
    super(message);
  }

  get fieldErrors(): Record<string, string[]> {
    return (this.details ?? {}) as Record<string, string[]>;
  }
}

function csrf(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export function idempotencyKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

type Listener = (e: ApiError) => void;
const listeners = new Set<Listener>();
/** Global hooks (session expiry, step-up prompts). */
export function onApiError(fn: Listener): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  ifMatch?: number;
  query?: Record<string, string | number | boolean | undefined | null | string[]>;
  form?: FormData;
  signal?: AbortSignal;
  idempotencyKey?: string;
}

export async function api<T = unknown>(path: string, opts: RequestOptions = {}): Promise<T> {
  const method = opts.method ?? (opts.body !== undefined || opts.form ? 'POST' : 'GET');
  const url = new URL(path.startsWith('/') ? path : `/api/v1/${path}`, window.location.origin);
  for (const [k, v] of Object.entries(opts.query ?? {})) {
    if (v === undefined || v === null || v === '') continue;
    if (Array.isArray(v)) v.forEach((x) => url.searchParams.append(`${k}[]`, x));
    else url.searchParams.set(k, String(v));
  }
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (method !== 'GET') {
    headers['X-CSRF-TOKEN'] = csrf();
    headers['Idempotency-Key'] = opts.idempotencyKey ?? idempotencyKey();
  }
  if (opts.ifMatch !== undefined) headers['If-Match'] = `"${opts.ifMatch}"`;
  let body: BodyInit | undefined;
  if (opts.form) body = opts.form;
  else if (opts.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(opts.body);
  }
  const res = await fetch(url, { method, headers, body, credentials: 'same-origin', signal: opts.signal, cache: 'no-store' });
  const text = await res.text();
  let json: unknown = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = null;
  }
  if (!res.ok) {
    const e = (json as { error?: { code?: string; message?: string; details?: Record<string, unknown>; correlation_id?: string } } | null)?.error;
    const err = new ApiError(res.status, e?.code ?? `http_${res.status}`, e?.message ?? 'Request failed.', e?.details ?? null, e?.correlation_id ?? null);
    listeners.forEach((l) => l(err));
    throw err;
  }
  return json as T;
}

export const ws = (workspaceId: string, path: string) => `workspaces/${workspaceId}/${path.replace(/^\//, '')}`;

export interface Page<T> {
  data: T[];
  next_cursor: string | null;
  prev_cursor: string | null;
}
