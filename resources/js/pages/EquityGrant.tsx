import { useState } from 'react';
import { useParams } from 'react-router';
import { useCommand, useResource, useResourceList } from '../api/hooks';
import { Card, Dialog, FormError, KeyValue, Notice, PageHeader, Query, StatusBadge } from '../components/ui';
import { ResourceSection } from '../components/resource';
import { date, humanize, money, num } from '../lib/format';

export default function EquityGrant() {
  const { id = '' } = useParams();
  const g = useResource('equity_grants', id);
  const tranches = useResourceList('equity_tranches', { filter: { grant_id: id }, limit: 200 });
  const accounts = useResourceList('accounts', { limit: 200 });
  const gen = useCommand(`equity/grants/${id}/tranches`);
  const event = useCommand(`equity/grants/${id}/events`);
  const [open, setOpen] = useState(false);
  const [ev, setEv] = useState<Record<string, string>>({ kind: 'exercise', event_date: new Date().toISOString().slice(0, 10), units: '', fmv: '', account_id: '', units_withheld: '', tax_withheld: '', exercise_method: 'cash' });
  const t = tranches.data?.data ?? [];
  const today = new Date().toISOString().slice(0, 10);
  const vested = t.filter((x) => String(x.vest_date) <= today && x.status !== 'forfeited').reduce((a, x) => a + Number(x.units), 0);
  const exercised = t.reduce((a, x) => a + Number(x.units_exercised ?? 0), 0);
  return (
    <Query q={g}>
      {(grant) => (
        <div className="space-y-6">
          <PageHeader eyebrow={`${String(grant.grant_type).toUpperCase()} grant`} title={String(grant.company_name)} description={`Granted ${date(grant.grant_date as string)} · ${num(grant.units as string, 0)} units${grant.strike_price ? ` · strike ${money(grant.strike_price as string, { cents: true })}` : ''}`}
            actions={<>
              <StatusBadge status={String(grant.status)} />
              <button className="btn-secondary btn-sm" onClick={() => gen.mutate({})}>{t.length ? 'Regenerate schedule' : 'Generate vesting schedule'}</button>
              <button className="btn-primary btn-sm" onClick={() => setOpen(true)}>Record event</button>
            </>} />
          <FormError error={gen.error} />
          <Card>
            <KeyValue items={[['Granted', num(grant.units as string, 0)], ['Vested to date', num(vested, 0)], ['Exercised / settled', num(exercised, 0)], ['Unvested', num(Number(grant.units) - vested, 0)],
              ['Expiration', date(grant.expiration_date as string)], ['83(b)', grant.election_83b_deadline ? `${grant.election_83b_filed ? 'Filed' : 'Not filed'} · deadline ${date(grant.election_83b_deadline as string)}` : '—']]} />
            {grant.grant_type === 'iso' && <div className="mt-4"><Notice>ISO exercises create an AMT preference equal to the spread. The tax engine includes ISO bargain elements recorded as exercise events.</Notice></div>}
          </Card>
          <ResourceSection type="equity_tranches" filter={{ grant_id: id }} />
          <ResourceSection type="equity_events" filter={{ grant_id: id }} description="Exercise and settlement events create tax lots with basis at FMV (RSU/NSO) or strike (ISO), avoiding W-2/1099-B double counting." />
          <Dialog open={open} onClose={() => setOpen(false)} title="Record equity event" wide>
            <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); event.mutate(Object.fromEntries(Object.entries(ev).filter(([, v]) => v !== '')), { onSuccess: () => setOpen(false) }); }}>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <label className="field"><span>Event</span><select value={ev.kind} onChange={(e) => setEv({ ...ev, kind: e.target.value })}>
                  {['vest', 'exercise', 'settle', 'sell', 'sell_to_cover', 'forfeit', 'expire', 'purchase'].map((k) => <option key={k} value={k}>{humanize(k)}</option>)}</select></label>
                <label className="field"><span>Date</span><input type="date" value={ev.event_date} onChange={(e) => setEv({ ...ev, event_date: e.target.value })} required /></label>
                <label className="field"><span>Units</span><input inputMode="decimal" value={ev.units} onChange={(e) => setEv({ ...ev, units: e.target.value })} required /></label>
                <label className="field"><span>FMV per share</span><input inputMode="decimal" value={ev.fmv} onChange={(e) => setEv({ ...ev, fmv: e.target.value })} /></label>
                <label className="field"><span>Shares withheld</span><input inputMode="decimal" value={ev.units_withheld} onChange={(e) => setEv({ ...ev, units_withheld: e.target.value })} /></label>
                <label className="field"><span>Tax withheld</span><input inputMode="decimal" value={ev.tax_withheld} onChange={(e) => setEv({ ...ev, tax_withheld: e.target.value })} /></label>
                <label className="field"><span>Deposit shares to account</span><select value={ev.account_id} onChange={(e) => setEv({ ...ev, account_id: e.target.value })}><option value="">— None —</option>{(accounts.data?.data ?? []).map((a) => <option key={a.id} value={a.id}>{a._title}</option>)}</select></label>
                <label className="field"><span>Exercise method</span><select value={ev.exercise_method} onChange={(e) => setEv({ ...ev, exercise_method: e.target.value })}>{['cash', 'cashless', 'net', 'sell_to_cover', 'swap'].map((k) => <option key={k} value={k}>{humanize(k)}</option>)}</select></label>
              </div>
              <FormError error={event.error} />
              <div className="flex justify-end gap-2"><button type="button" className="btn-secondary" onClick={() => setOpen(false)}>Cancel</button><button className="btn-primary" disabled={event.isPending}>Record</button></div>
            </form>
          </Dialog>
        </div>
      )}
    </Query>
  );
}
