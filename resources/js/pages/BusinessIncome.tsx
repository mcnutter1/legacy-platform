import { useState } from 'react';
import { Link } from 'react-router';
import { useWsQuery } from '../api/hooks';
import { useWorkspace } from '../app/workspace';
import { ResourceSection } from '../components/resource';
import { SourceIcon } from '../components/sources';
import { Card, Empty, KeyValue, Money, Notice, PageHeader, Query, StatusBadge } from '../components/ui';
import { humanize, relative } from '../lib/format';

interface Conn { id: string; label: string; status: string; last_success_at: string | null }
interface Business {
  entity_id: string; name: string; kind: string | null; tax_classification: string | null; owners: number; lines: number;
  book: { income: string; cogs: string; expense: string; net: string };
  schedule_c: { gross: string; returns: string; cogs: string; expenses: Record<string, string>; net: string; meals_nondeductible: string; excluded: string; unmapped: number };
  connection: (Conn & { tax_estimate: { net_business_income: string | null; business_tax: string | null } | null }) | null;
}
interface Rental {
  property_id: string; name: string; kind: string; rents: string; platform_fees: string; expenses: string; improvements: string; undetermined: number; undetermined_amount: string;
  days: Record<string, number>; stays: number;
  connection: (Conn & { reconciliation: { pms_gross_revenue: string | null; revenue_difference: string | null } | null }) | null;
}
interface Data { year: number; businesses: Business[]; rentals: Rental[]; connections: { id: string; provider: string; label: string; status: string }[] }

const LINE: Record<string, string> = {
  advertising: 'Advertising', car_truck: 'Car & truck', commissions: 'Commissions & fees', contract_labor: 'Contract labor', depreciation: 'Depreciation', insurance: 'Insurance',
  interest: 'Interest', legal_professional: 'Legal & professional', office: 'Office', rent: 'Rent or lease', repairs: 'Repairs', supplies: 'Supplies', taxes_licenses: 'Taxes & licenses',
  travel: 'Travel', meals: 'Meals (deductible 50%)', utilities: 'Utilities', wages: 'Wages', other: 'Other',
};

function eligible(b: Business): string | null {
  if (b.kind === 'sole_prop' || (b.kind === 'llc' && b.tax_classification === 'disregarded')) return b.owners > 0 ? null : 'Record the owner so this reaches the right Schedule C.';
  if (b.kind === 'llc' && !b.tax_classification) return 'Set the LLC tax classification (disregarded for a single-member LLC) to report it on Schedule C.';
  return 'Reported through a K-1, not Schedule C. Add the K-1 under Tax when it arrives.';
}

export default function BusinessIncome() {
  const { path } = useWorkspace();
  const thisYear = new Date().getFullYear();
  const [year, setYear] = useState(thisYear);
  const q = useWsQuery<Data>(['business-and-rentals'], 'earnings/business-and-rentals', { query: { year } });
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Earnings" title="Businesses & rentals"
        description="Side-business books (McNutt Finance) and rental activity (Beach House PMS) for the year, as the tax engine will see them. Business profit feeds Schedule C and self-employment tax; rentals feed Schedule E with the vacation-home day tests."
        actions={<>
          <label className="field"><span className="sr-only">Tax year</span>
            <select value={year} onChange={(e) => setYear(Number(e.target.value))}>{[thisYear, thisYear - 1, thisYear - 2].map((y) => <option key={y} value={y}>{y}</option>)}</select></label>
          <Link className="btn-secondary" to={path('integrations')}>Manage connections</Link>
        </>} />
      <Query q={q}>
        {(d) => (
          <>
            <section aria-labelledby="biz-h" className="space-y-4">
              <h2 id="biz-h" className="text-xl">Side businesses</h2>
              {d.businesses.length === 0 ? (
                <Card><Empty title="No business books connected" action={<Link className="btn-primary" to={path('integrations')}>Connect McNutt Finance</Link>}>
                  Connect each business's Finance account (one key per business). Until then, Schedule C uses transactions tagged to the business entity.</Empty></Card>
              ) : d.businesses.map((b) => {
                const warn = eligible(b);
                const exp = Object.entries(b.schedule_c.expenses).filter(([, v]) => Number(v) !== 0);
                return (
                  <Card key={b.entity_id} title={<span className="flex items-center gap-2"><SourceIcon provider="invoices" size={22} />{b.name}</span>}
                    subtitle={<>{humanize(b.kind ?? 'business')}{b.tax_classification ? ` · ${humanize(b.tax_classification)}` : ''} · {b.lines} cash events in {d.year}
                      {b.connection?.last_success_at ? ` · synced ${relative(b.connection.last_success_at)}` : ''}</>}
                    actions={b.connection ? <StatusBadge status={b.connection.status} label={humanize(b.connection.status)} /> : undefined}>
                    {warn && <Notice tone="warning">{warn}</Notice>}
                    <div className="mt-3 grid grid-cols-1 gap-6 md:grid-cols-2">
                      <div>
                        <h3 className="mb-2 text-sm font-semibold">Books (cash basis)</h3>
                        <KeyValue items={[['Income', <Money key="i" value={b.book.income} cents />], ['Cost of goods sold', <Money key="c" value={b.book.cogs} cents />],
                          ['Expenses', <Money key="e" value={b.book.expense} cents />], ['Net', <Money key="n" value={b.book.net} cents />]]} />
                      </div>
                      <div>
                        <h3 className="mb-2 text-sm font-semibold">Schedule C view</h3>
                        <div className="table-wrap">
                          <table className="data-table">
                            <caption className="sr-only">Schedule C lines for {b.name}</caption>
                            <tbody>
                              <tr><th scope="row">Gross receipts</th><td className="text-right"><Money value={b.schedule_c.gross} cents /></td></tr>
                              {Number(b.schedule_c.returns) !== 0 && <tr><th scope="row">Returns &amp; allowances</th><td className="text-right"><Money value={b.schedule_c.returns} cents /></td></tr>}
                              {Number(b.schedule_c.cogs) !== 0 && <tr><th scope="row">Cost of goods sold</th><td className="text-right"><Money value={b.schedule_c.cogs} cents /></td></tr>}
                              {exp.map(([k, v]) => <tr key={k}><th scope="row">{LINE[k] ?? humanize(k)}</th><td className="text-right"><Money value={v} cents /></td></tr>)}
                              <tr><th scope="row">Net profit</th><td className="text-right font-semibold"><Money value={b.schedule_c.net} cents /></td></tr>
                            </tbody>
                          </table>
                        </div>
                        <p className="mt-2 text-xs text-muted">
                          {Number(b.schedule_c.meals_nondeductible) > 0 && <>Meals limited to 50% (<Money value={b.schedule_c.meals_nondeductible} cents /> not deducted). </>}
                          {Number(b.schedule_c.excluded) !== 0 && <>Excluded from Schedule C: <Money value={b.schedule_c.excluded} cents /> (owner draws, income-tax payments). </>}
                          {b.schedule_c.unmapped > 0 && <span className="text-warning">{b.schedule_c.unmapped} account(s) need a Schedule C line. </span>}
                          {b.connection && <Link className="link" to={`${path('integrations')}?open=${b.connection.id}`}>Edit account mapping</Link>}
                        </p>
                        {b.connection?.tax_estimate && <p className="mt-1 text-xs text-muted">Finance's own estimate (provisional): added tax <Money value={b.connection.tax_estimate.business_tax} cents />.</p>}
                      </div>
                    </div>
                  </Card>
                );
              })}
            </section>

            <section aria-labelledby="rent-h" className="space-y-4">
              <h2 id="rent-h" className="text-xl">Rental properties</h2>
              {d.rentals.length === 0 ? (
                <Card><Empty title="No rental activity" action={<Link className="btn-primary" to={path('integrations')}>Connect Beach House PMS</Link>}>
                  Connect the property-management system to bring in bookings, owner stays and expenses, or add stays on the property page.</Empty></Card>
              ) : (
                <Card pad={false}>
                  <div className="table-wrap">
                    <table className="data-table">
                      <caption className="sr-only">Rental income, expenses and days by property for {d.year}</caption>
                      <thead><tr><th>Property</th><th className="text-right">Rents</th><th className="text-right">Platform fees</th><th className="text-right">Expenses</th>
                        <th className="text-right">Rental days</th><th className="text-right">Personal days</th><th>Needs review</th><th>Source</th></tr></thead>
                      <tbody>{d.rentals.map((r) => {
                        const diff = r.connection?.reconciliation?.revenue_difference;
                        return (
                          <tr key={r.property_id}>
                            <td><Link className="link" to={path(`real-estate/${r.property_id}`)}>{r.name}</Link><span className="block text-xs text-muted">{humanize(r.kind)} · {r.stays} stays</span></td>
                            <td className="text-right"><Money value={r.rents} cents /></td>
                            <td className="text-right"><Money value={r.platform_fees} cents /></td>
                            <td className="text-right"><Money value={r.expenses} cents /></td>
                            <td className="num text-right">{r.days.rental}</td>
                            <td className="num text-right">{r.days.personal}{r.days.owner_maintenance ? <span className="block text-xs text-muted">+{r.days.owner_maintenance} maintenance</span> : null}</td>
                            <td className="text-xs">{r.undetermined > 0 ? <span className="text-warning">{r.undetermined} expense{r.undetermined === 1 ? '' : 's'} (<Money value={r.undetermined_amount} cents />): repair or improvement?</span> : '—'}</td>
                            <td className="text-xs">{r.connection ? <span className="flex items-center gap-1"><SourceIcon provider="property_manager" size={16} />{r.connection.label}
                              {diff != null && diff !== '0.00' && diff !== '-0.00' ? <span className="text-warning"> · differs from PMS by {diff}</span> : null}</span> : 'Entered in Legacy'}</td>
                          </tr>
                        );
                      })}</tbody>
                    </table>
                  </div>
                </Card>
              )}
              <p className="text-xs text-muted">Rents include cleaning fees and kept damage deposits, net of refunds. Lodging taxes the PMS collected and remitted are not income. Reclassify family or donated stays on the property page; your changes survive re-syncs.</p>
            </section>
          </>
        )}
      </Query>
      <ResourceSection type="business_ledger_lines" title="Business cash events" description="Read-only lines imported from connected books." />
    </div>
  );
}
