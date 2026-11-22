import { useMutation, useQuery, useQueryClient, type UseQueryOptions } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { api, ws, type Page } from './client';
import type { AnyRecord, Job, Meta } from './types';
import { useWorkspace } from '../app/workspace';

/** Query keys always start with the workspace id so a scope switch or logout clears everything in one call. */
export function useWsQuery<T>(key: unknown[], path: string, opts: { query?: Record<string, unknown>; enabled?: boolean } & Partial<UseQueryOptions<T>> = {}) {
  const { id } = useWorkspace();
  const { query, enabled, ...rest } = opts;
  return useQuery<T>({
    queryKey: [id, ...key, query ?? null],
    queryFn: ({ signal }) => api<{ data: T }>(ws(id, path), { query: query as Record<string, string>, signal }).then((r) => (r as { data: T }).data),
    enabled: enabled ?? true,
    ...rest,
  });
}

export function useMeta() {
  return useQuery<Meta>({ queryKey: ['meta'], queryFn: () => api<{ data: Meta }>('meta').then((r) => r.data), staleTime: Infinity });
}

export function useResourceList(type: string, params: Record<string, unknown> = {}, enabled = true) {
  const { id } = useWorkspace();
  return useQuery<Page<AnyRecord>>({
    queryKey: [id, 'r', type, params],
    queryFn: ({ signal }) => api<Page<AnyRecord>>(ws(id, `r/${type}`), { query: flatten(params), signal }),
    enabled,
  });
}

export function useResource(type: string, recordId: string | undefined) {
  const { id } = useWorkspace();
  return useQuery<AnyRecord>({
    queryKey: [id, 'r', type, 'one', recordId],
    queryFn: ({ signal }) => api<{ data: AnyRecord }>(ws(id, `r/${type}/${recordId}`), { signal }).then((r) => r.data),
    enabled: !!recordId,
  });
}

function flatten(params: Record<string, unknown>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue;
    if (k === 'filter' && typeof v === 'object') {
      for (const [fk, fv] of Object.entries(v as Record<string, unknown>)) if (fv !== undefined && fv !== null && fv !== '') out[`filter[${fk}]`] = String(fv);
    } else out[k] = String(v);
  }
  return out;
}

export function useSaveResource(type: string) {
  const { id } = useWorkspace();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (vars: { id?: string; data: Record<string, unknown>; version?: number }) =>
      vars.id
        ? api<{ data: AnyRecord }>(ws(id, `r/${type}/${vars.id}`), { method: 'PATCH', body: vars.data, ifMatch: vars.version })
        : api<{ data: AnyRecord }>(ws(id, `r/${type}`), { method: 'POST', body: vars.data }),
    onSuccess: () => qc.invalidateQueries({ queryKey: [id] }),
  });
}

export function useArchiveResource(type: string) {
  const { id } = useWorkspace();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (vars: { id: string; version: number }) => api(ws(id, `r/${type}/${vars.id}`), { method: 'DELETE', ifMatch: vars.version }),
    onSuccess: () => qc.invalidateQueries({ queryKey: [id] }),
  });
}

/** POST a workspace command; invalidates workspace queries afterwards. */
export function useCommand<TBody = Record<string, unknown>, TRes = unknown>(path: string | ((b: TBody) => string), method: 'POST' | 'PUT' = 'POST') {
  const { id } = useWorkspace();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: TBody) => api<{ data: TRes }>(ws(id, typeof path === 'function' ? path(body) : path), { method, body: body ?? {} }).then((r) => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: [id] }),
  });
}

/** Poll a job until it reaches a terminal state. */
export function useJob(jobId: string | null | undefined) {
  const { id } = useWorkspace();
  const qc = useQueryClient();
  const [done, setDone] = useState(false);
  const q = useQuery<Job>({
    queryKey: [id, 'job', jobId],
    queryFn: () => api<{ data: Job }>(ws(id, `jobs/${jobId}`)).then((r) => r.data),
    enabled: !!jobId,
    refetchInterval: (query) => (['succeeded', 'failed', 'timed_out', 'cancelled'].includes(query.state.data?.status ?? '') ? false : 1500),
  });
  useEffect(() => {
    const s = q.data?.status;
    if (!done && s && ['succeeded', 'failed', 'timed_out', 'cancelled'].includes(s)) {
      setDone(true);
      void qc.invalidateQueries({ queryKey: [id], predicate: (query) => query.queryKey[1] !== 'job' });
    }
  }, [q.data?.status, done, qc, id]);
  useEffect(() => setDone(false), [jobId]);
  return q;
}
