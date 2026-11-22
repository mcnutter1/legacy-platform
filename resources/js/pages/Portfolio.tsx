import { useWsQuery } from '../api/hooks';
import { Card, Money, Notice, PageHeader, Query, Stat } from '../components/ui';
import { ChartFrame, Donut } from '../components/charts';
import { ResourceSection } from '../components/resource';
import { date, humanize, money, num, pct } from '../lib/format';

interface PortfolioData {
  as_of: string; total_value: string;
  positions: { account: string; symbol: string | null; name: string | null; quantity: string; price: string | null; value: string; cost_basis: string | null; unrealized: string | null; price_as_of: string; asset_class: string; tax_bucket: string }[];
  exposure: Record<string, { key: string; value: string; weight: string }[]>;
  flags: { code: string; message: string }[];
  coverage: { positions: number; unvalued: { account: string; symbol: string }[]; stale_prices: { account: string; symbol: string; price_as_of: string }[] };
  methodology: string;
}

export default function Portfolio() {
  const q = useWsQuery<PortfolioData>(['portfolio'], 'portfolio');
  return (
    <div className="space-y-6">
      <PageHeader eyebrow="Wealth" title="Investments" description="Positions, allocation and concentration across every account you can see. Time- and money-weighted returns are on each account." />
      <Query q={q}>
        {(d) => (
          <>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Stat label="Portfolio value" value={money(d.total_value)} hint={`As of ${date(d.as_of)}`} />
              <Stat label="Positions" value={d.coverage.positions} hint={`${d.coverage.unvalued.length} unpriced`} />
              <Stat label="Stale prices" value={d.coverage.stale_prices.length} tone={d.coverage.stale_prices.length ? 'negative' : 'neutral'} hint="Older than 7 days" />
            </div>
            {d.flags.length > 0 && <Notice tone="warning">{d.flags.map((f) => f.message).join(' ')}</Notice>}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              {(['asset_class', 'tax_bucket', 'sector', 'issuer'] as const).map((k) => (
                <Card key={k} title={`By ${humanize(k)}`}>
                  <ChartFrame title={`Exposure by ${humanize(k)}`} chart={<Donut items={(d.exposure[k] ?? []).slice(0, 8).map((e) => ({ label: humanize(e.key), value: Number(e.value) }))} />}
                    table={<table className="data-table"><tbody>{(d.exposure[k] ?? []).map((e) => <tr key={e.key}><td>{humanize(e.key)}</td><td className="text-right"><Money value={e.value} /></td><td className="num text-right">{pct(e.weight)}</td></tr>)}</tbody></table>} />
                </Card>
              ))}
            </div>
            <Card title="Positions" pad={false}>
              <div className="table-wrap">
                <table className="data-table">
                  <thead><tr><th>Security</th><th>Account</th><th className="text-right">Quantity</th><th className="text-right">Price</th><th className="text-right">Value</th><th className="text-right">Unrealized</th><th>Priced</th></tr></thead>
                  <tbody>
                    {d.positions.map((p, i) => (
                      <tr key={i}>
                        <td><span className="font-medium">{p.symbol}</span> <span className="text-muted">{p.name}</span></td>
                        <td className="text-muted">{p.account}</td>
                        <td className="num text-right">{num(p.quantity, 4)}</td>
                        <td className="num text-right">{money(p.price, { cents: true })}</td>
                        <td className="text-right"><Money value={p.value} /></td>
                        <td className="text-right"><Money value={p.unrealized} sign /></td>
                        <td className="num text-muted">{date(p.price_as_of)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
            <p className="text-xs text-muted">{d.methodology}</p>
          </>
        )}
      </Query>
      <ResourceSection type="instruments" description="Securities master: classification drives allocation and concentration." />
      <ResourceSection type="valuations" filter={{}} title="Valuations" description="Accepted valuations by purpose (market, tax, replacement, estate). Proposed values require acceptance." />
    </div>
  );
}
