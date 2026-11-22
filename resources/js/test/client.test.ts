import { afterEach, describe, expect, it, vi } from 'vitest';
import { api, ApiError, onApiError } from '../api/client';

function mockFetch(status: number, body: unknown) {
  const fn = vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
  vi.stubGlobal('fetch', fn);
  return fn;
}

afterEach(() => vi.unstubAllGlobals());

describe('api client', () => {
  it('sends CSRF and an idempotency key on mutations, not on reads', async () => {
    document.head.innerHTML = '<meta name="csrf-token" content="tok">';
    const f = mockFetch(200, { data: { ok: true } });
    await api('workspaces/W/r/persons');
    await api('workspaces/W/r/persons', { body: { display_name: 'A' } });
    const [, getInit] = f.mock.calls[0] as unknown as [URL, RequestInit];
    const [, postInit] = f.mock.calls[1] as unknown as [URL, RequestInit];
    expect((getInit.headers as Record<string, string>)['Idempotency-Key']).toBeUndefined();
    expect((postInit.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('tok');
    expect((postInit.headers as Record<string, string>)['Idempotency-Key']).toBeTruthy();
    expect(postInit.method).toBe('POST');
  });

  it('serializes array query params with brackets', async () => {
    const f = mockFetch(200, { data: [] });
    await api('x', { query: { runs: ['a', 'b'], q: 'z', skip: undefined } });
    const url = (f.mock.calls[0] as unknown as [URL])[0];
    expect(url.search).toBe('?runs%5B%5D=a&runs%5B%5D=b&q=z');
  });

  it('sends If-Match on versioned updates', async () => {
    const f = mockFetch(200, { data: {} });
    await api('x', { method: 'PATCH', body: {}, ifMatch: 3 });
    const [, init] = f.mock.calls[0] as unknown as [URL, RequestInit];
    expect((init.headers as Record<string, string>)['If-Match']).toBe('"3"');
  });

  it('raises structured errors and notifies listeners', async () => {
    mockFetch(403, { error: { code: 'step_up_required', message: 'Verify', correlation_id: 'c1' } });
    const seen: string[] = [];
    const off = onApiError((e) => seen.push(e.code));
    await expect(api('x', { body: {} })).rejects.toBeInstanceOf(ApiError);
    off();
    expect(seen).toEqual(['step_up_required']);
  });
});
