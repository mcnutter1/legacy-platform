import { useState } from 'react';
import { Link } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { api, ws } from '../api/client';
import { useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { providerName, SourceIcon } from '../components/sources';
import { Card, Empty, FormError, Notice, PageHeader, Query, StatusBadge } from '../components/ui';
import { date, humanize, relative } from '../lib/format';

interface Run { id: string; type: string; subject_id: string | null; status: string; attempts: number; trigger: 'scheduled' | 'manual'; dry_run: boolean; created_at: string | null;
  started_at: string | null; finished_at: string | null; duration_s: number | null; error: string | null; subject?: string | null }
interface Source { id: string; provider: string; label: string; status: string; approved: boolean; authorized: boolean; interval_hours: number; scheduled: boolean; next_run_at: string | null;
  last_success_at: string | null; last_attempt_at: string | null; last_error: string | null; edit_version: number; can_run: boolean; blocked_reason: string | null; runs: Run[] }
interface OnDemand { kind: string; label: string; subject_id: string; subject: string; county: string | null; can_run: boolean; blocked_reason: string | null; runs: Run[] }
interface SystemTask { task: string; label: string; cadence: string; description: string; last_started_at: string | null; last_finished_at: string | null; last_status: string | null;
  last_duration_ms: number | null; failures: number }
interface Data { now: string; sources: Source[]; on_demand: OnDemand[]; system: SystemTask[]; queue: { queued: number; running: number }; history: Run[] }
interface RunDetail extends Run { params: Record<string, unknown>; result: unknown; metrics: Record<string, unknown> | null; max_attempts: number; available_at: string | null; initiated_by_you: boolean }

const ACTIVE = ['queued', 'leased', 'retryable'];
const INTERVALS = [1, 3, 6, 12, 24, 48, 168];
const TYPE_LABEL: Record<string, string> = { 'integration.sync': 'Data-source sync', 'property.county_records': 'County records' };

function when(iso: string | null, future = false): string {
  if (!iso) return '—';
  const t = new Date(iso).getTime();
  if (future && t <= Date.now()) return 'due now';
  return relative(iso);
}

export default function Schedules() {
  const [openRun, setOpenRun] = useState<string | null>(null);
  const q = useWsQuery<Data>(['schedules'], 'schedules', {
    refetchInterval: (query) => {
      const d = query.state.data as Data | undefined;
      return d && (d.queue.queued + d.queue.running > 0) ? 4000 : 30000;
    },
  });
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Admin" title="Schedules"
        description="Every automated puller in one place: scheduled data-source syncs, on-demand public-record pulls and the system tasks that run them. Run anything now and inspect the full output of each run." />
      {openRun && <RunPanel key={openRun} id={openRun} onClose={() => setOpenRun(null)} />}
      <Query q={q}>
        {(d) => (
          <>
            {d.queue.queued + d.queue.running > 0 && <Notice>{d.queue.running} running · {d.queue.queued} waiting in this workspace's job queue. This page refreshes automatically.</Notice>}
            <SourcesCard d={d} onOpen={setOpenRun} />
            <OnDemandCard items={d.on_demand} onOpen={setOpenRun} />
            <SystemCard tasks={d.system} />
            <HistoryCard runs={d.history} onOpen={setOpenRun} />
          </>
        )}
      </Query>
    </div>
  );
}

function useRun() {
  const { id: wsId } = useWorkspace();
  const qc = useQueryClient();
  const [busy, setBusy] = useState<string | null>(null);
  const [err, setErr] = useState<unknown>(null);
  const run = async (key: string, path: string, onJob: (id: string) => void) => {
    setBusy(key); setErr(null);
    try {
      const r = await api<{ data: { job: { id: string } } }>(ws(wsId, path), { body: {} });
      onJob(r.data.job.id);
      await qc.invalidateQueries({ queryKey: [wsId, 'schedules'] });
    } catch (e) { setErr(e); } finally { setBusy(null); }
  };
  return { busy, err, run };
}

function RunBadge({ r, onOpen }: { r: Run | undefined; onOpen: (id: string) => void }) {
  if (!r) return <span className="text-xs text-muted">Never run</span>;
  return (
    <button type="button" className="link text-left text-xs" onClick={() => onOpen(r.id)}>
      <StatusBadge status={r.status} label={humanize(r.status)} /> {r.dry_run ? 'preview' : r.trigger} {when(r.created_at)}{r.duration_s != null ? ` · ${r.duration_s}s` : ''}
      <span className="sr-only"> — show output</span>
    </button>
  );
}

function SourcesCard({ d, onOpen }: { d: Data; onOpen: (id: string) => void }) {
  const { id: wsId, path } = useWorkspace();
  const qc = useQueryClient();
  const { busy, err, run } = useRun();
  const [saveErr, setSaveErr] = useState<unknown>(null);
  const setInterval_ = async (s: Source, hours: number) => {
    setSaveErr(null);
    try {
      await api(ws(wsId, `integrations/${s.id}`), { method: 'PATCH', body: { sync_interval_hours: hours }, ifMatch: s.edit_version });
      await qc.invalidateQueries({ queryKey: [wsId, 'schedules'] });
    } catch (e) { setSaveErr(e); }
  };
  return (
    <Card title="Data sources" subtitle="Synced automatically on their interval; the scheduler checks every 15 minutes." pad={false}
      actions={<Link className="btn-secondary btn-sm" to={path('integrations')}>Manage connections</Link>}>
      {d.sources.length === 0 ? <div className="card-pad"><Empty title="No connections">Add Monarch, Snowball, the PMS or McNutt Finance on the Integrations page.</Empty></div> : (
        <div className="table-wrap">
          <table className="data-table">
            <caption className="sr-only">Scheduled data-source syncs</caption>
            <thead><tr><th>Source</th><th>Every</th><th>Last success</th><th>Next run</th><th>Last run</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>{d.sources.map((s) => {
              const running = s.runs.some((r) => ACTIVE.includes(r.status));
              return (
                <tr key={s.id}>
                  <td><span className="flex items-center gap-2"><SourceIcon provider={s.provider} size={20} /><span>{s.label}<span className="block text-xs text-muted">{providerName(s.provider)} · {humanize(s.status)}</span></span></span>
                    {s.last_error && <span className="mt-1 block max-w-md text-xs text-negative">{s.last_error}</span>}</td>
                  <td>
                    <label className="sr-only" htmlFor={`int-${s.id}`}>Sync interval for {s.label}</label>
                    <select id={`int-${s.id}`} value={s.interval_hours} disabled={!s.can_run} onChange={(e) => void setInterval_(s, Number(e.target.value))}>
                      {[...new Set([...INTERVALS, s.interval_hours])].sort((a, b) => a - b).map((h) => <option key={h} value={h}>{h < 24 ? `${h} h` : h % 24 === 0 ? `${h / 24} d` : `${h} h`}</option>)}
                    </select>
                  </td>
                  <td className="text-xs">{s.last_success_at ? <span title={date(s.last_success_at)}>{relative(s.last_success_at)}</span> : 'Never'}</td>
                  <td className="text-xs">{s.scheduled ? when(s.next_run_at, true) : <span className="text-warning">{s.blocked_reason ?? 'Paused'}</span>}</td>
                  <td><RunBadge r={s.runs[0]} onOpen={onOpen} /></td>
                  <td className="text-right">
                    <button className="btn-primary btn-sm" disabled={!s.can_run || running || busy !== null} onClick={() => void run(s.id, `integrations/${s.id}/sync`, onOpen)}>
                      {running ? 'Running…' : busy === s.id ? 'Starting…' : 'Run now'}</button>
                  </td>
                </tr>
              );
            })}</tbody>
          </table>
        </div>
      )}
      <div className="card-pad pt-0"><FormError error={err ?? saveErr} /></div>
    </Card>
  );
}

function OnDemandCard({ items, onOpen }: { items: OnDemand[]; onOpen: (id: string) => void }) {
  const { busy, err, run } = useRun();
  if (items.length === 0) return null;
  return (
    <Card title="Public-record pullers" subtitle="Run on demand, one property at a time, to respect county servers." pad={false}>
      <div className="table-wrap">
        <table className="data-table">
          <caption className="sr-only">On-demand public-record pullers</caption>
          <thead><tr><th>Puller</th><th>Property</th><th>Last run</th><th><span className="sr-only">Actions</span></th></tr></thead>
          <tbody>{items.map((i) => {
            const running = i.runs.some((r) => ACTIVE.includes(r.status));
            return (
              <tr key={`${i.kind}-${i.subject_id}`}>
                <td>{i.label}{i.county ? <span className="block text-xs text-muted">{i.county} County</span> : null}</td>
                <td>{i.subject}</td>
                <td><RunBadge r={i.runs[0]} onOpen={onOpen} /></td>
                <td className="text-right">
                  {i.can_run ? <button className="btn-secondary btn-sm" disabled={running || busy !== null} onClick={() => void run(i.subject_id, `properties/${i.subject_id}/county-records`, onOpen)}>
                    {running ? 'Running…' : 'Run now'}</button> : <span className="text-xs text-muted">{i.blocked_reason}</span>}
                </td>
              </tr>
            );
          })}</tbody>
        </table>
      </div>
      <div className="card-pad pt-0"><FormError error={err} /></div>
    </Card>
  );
}

function SystemCard({ tasks }: { tasks: SystemTask[] }) {
  return (
    <Card title="System tasks" subtitle="Run by cron on the server. Shown so you can confirm the machinery is alive." pad={false}>
      <div className="table-wrap">
        <table className="data-table">
          <caption className="sr-only">System scheduled tasks</caption>
          <thead><tr><th>Task</th><th>Cadence</th><th>Last run</th><th>Result</th></tr></thead>
          <tbody>{tasks.map((t) => (
            <tr key={t.task}>
              <td>{t.label}<span className="block text-xs text-muted">{t.description}</span></td>
              <td className="text-xs">{t.cadence}</td>
              <td className="text-xs">{t.last_started_at ? <span title={date(t.last_started_at)}>{relative(t.last_started_at)}</span> : 'Not yet recorded'}</td>
              <td className="text-xs">{t.last_status ? <><StatusBadge status={t.last_status} label={humanize(t.last_status)} />{t.last_duration_ms != null ? ` ${(t.last_duration_ms / 1000).toFixed(1)}s` : ''}
                {t.failures > 1 ? <span className="block text-negative">{t.failures} failures in a row</span> : null}</> : t.last_started_at ? 'Running' : '—'}</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
    </Card>
  );
}

function HistoryCard({ runs, onOpen }: { runs: Run[]; onOpen: (id: string) => void }) {
  return (
    <Card title="Run history" subtitle="Most recent 50 runs you can see. Select one for its full output." pad={false}>
      {runs.length === 0 ? <div className="card-pad"><Empty title="No runs yet" /></div> : (
        <div className="table-wrap">
          <table className="data-table">
            <caption className="sr-only">Run history</caption>
            <thead><tr><th>When</th><th>What</th><th>Trigger</th><th>Status</th><th className="text-right">Duration</th><th><span className="sr-only">Output</span></th></tr></thead>
            <tbody>{runs.map((r) => (
              <tr key={r.id}>
                <td className="text-xs"><span title={r.created_at ? date(r.created_at) : ''}>{when(r.created_at)}</span></td>
                <td>{TYPE_LABEL[r.type] ?? r.type}{r.dry_run ? ' (preview)' : ''}<span className="block text-xs text-muted">{r.subject ?? '—'}</span></td>
                <td className="text-xs">{humanize(r.trigger)}</td>
                <td><StatusBadge status={r.status} label={humanize(r.status)} />{r.attempts > 1 ? <span className="block text-xs text-muted">{r.attempts} attempts</span> : null}
                  {r.error && <span className="block max-w-sm truncate text-xs text-negative" title={r.error}>{r.error}</span>}</td>
                <td className="num text-right">{r.duration_s != null ? `${r.duration_s}s` : '—'}</td>
                <td className="text-right"><button className="btn-secondary btn-sm" onClick={() => onOpen(r.id)}>Output</button></td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      )}
    </Card>
  );
}

function RunPanel({ id, onClose }: { id: string; onClose: () => void }) {
  const { id: wsId } = useWorkspace();
  const qc = useQueryClient();
  const q = useWsQuery<RunDetail>(['schedule-run', id], `schedules/runs/${id}`, {
    refetchInterval: (query) => {
      const d = query.state.data as RunDetail | undefined;
      if (d && !ACTIVE.includes(d.status)) { void qc.invalidateQueries({ queryKey: [wsId, 'schedules'] }); return false; }
      return 2000;
    },
  });
  return (
    <Card title="Run output" subtitle={id} actions={<button className="btn-secondary btn-sm" onClick={onClose}>Close</button>}>
      <Query q={q}>
        {(r) => (
          <div className="space-y-4" aria-live="polite">
            <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
              <div><dt className="eyebrow">What</dt><dd>{TYPE_LABEL[r.type] ?? r.type}{r.dry_run ? ' (preview)' : ''}</dd></div>
              <div><dt className="eyebrow">Status</dt><dd><StatusBadge status={r.status} label={humanize(r.status)} />{ACTIVE.includes(r.status) ? ' …' : ''}</dd></div>
              <div><dt className="eyebrow">Trigger</dt><dd>{humanize(r.trigger)}{r.initiated_by_you ? ' (you)' : ''}</dd></div>
              <div><dt className="eyebrow">Attempts</dt><dd>{r.attempts} of {r.max_attempts}</dd></div>
              <div><dt className="eyebrow">Queued</dt><dd>{r.created_at ? date(r.created_at) : '—'} {r.created_at ? new Date(r.created_at).toLocaleTimeString() : ''}</dd></div>
              <div><dt className="eyebrow">Started</dt><dd>{r.started_at ? new Date(r.started_at).toLocaleTimeString() : 'waiting for a worker'}</dd></div>
              <div><dt className="eyebrow">Finished</dt><dd>{r.finished_at ? new Date(r.finished_at).toLocaleTimeString() : '—'}</dd></div>
              <div><dt className="eyebrow">Duration</dt><dd>{r.duration_s != null ? `${r.duration_s}s` : '—'}</dd></div>
            </dl>
            {r.error && <Notice tone="danger">{r.error}</Notice>}
            {r.status === 'retryable' && r.available_at && <Notice tone="warning">Will retry {when(r.available_at, true)}.</Notice>}
            <Output title="Result" value={r.result} />
            <Output title="Metrics" value={r.metrics} />
            <Output title="Parameters" value={r.params} />
          </div>
        )}
      </Query>
    </Card>
  );
}

function Output({ title, value }: { title: string; value: unknown }) {
  if (value == null || (typeof value === 'object' && Object.keys(value as object).length === 0)) return null;
  return (
    <section>
      <h3 className="mb-1 text-sm font-semibold">{title}</h3>
      <pre className="max-h-96 overflow-auto rounded-lg bg-sunken p-3 text-xs leading-relaxed" tabIndex={0}>{JSON.stringify(value, null, 2)}</pre>
    </section>
  );
}
