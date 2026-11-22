<?php

namespace App\Services\Integrations\Providers;

use App\Services\Integrations\Shape;

/**
 * An MCP data source. Tool names and payload shapes are discovered at connect time (tools/list) and the
 * suggested mapping must be previewed and approved before a sync writes anything.
 */
abstract class Provider
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** oauth | api_key */
    abstract public function authKind(): string;

    abstract public function defaultUrl(): string;

    /** Hosts the server URL (and OAuth endpoints) may use. */
    abstract public function allowedHosts(): array;

    abstract public function featureFlag(): string;

    /** @return array{summary: string, steps: list<string>, links: list<array{label: string, url: string}>, notes: list<string>} */
    abstract public function instructions(): array;

    /** Regexes (tried in order) used to suggest which tool provides each role. */
    abstract protected function toolPatterns(): array;

    public function enabled(): bool
    {
        return (bool) config('legacy.features.'.$this->featureFlag());
    }

    /** mcp: tools discovered and mapped at connect time; rest: a documented JSON API with a fixed contract. */
    public function transport(): string
    {
        return 'mcp';
    }

    /** Record a connection feeds (property | entity), chosen when the connection is created; null = creates accounts. */
    public function target(): ?string
    {
        return null;
    }

    public function hostAllowed(string $url): bool
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['port'])) {
            return false;
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        foreach ($this->allowedHosts() as $h) {
            if ($host === $h || str_ends_with($host, '.'.$h)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string|null> role => tool name */
    public function suggestTools(array $catalog): array
    {
        $names = array_column($catalog, 'name');
        $out = [];
        foreach ($this->toolPatterns() as $role => $patterns) {
            $out[$role] = null;
            foreach ($patterns as $re) {
                foreach ($names as $n) {
                    if (preg_match($re, $n)) {
                        $out[$role] = $n;
                        break 2;
                    }
                }
            }
        }

        return $out;
    }

    public function accountKeys(): array
    {
        return ['accounts', 'portfolios', 'items', 'data', 'results'];
    }

    public function isAggregate(array $row): bool
    {
        return false;
    }

    /** Why an account row should not become a Legacy account (null = import it). */
    public function skipReason(array $row): ?string
    {
        return $this->isAggregate($row) ? 'combined/aggregate portfolio' : null;
    }

    /** Provider notices carried in a payload (e.g. accounts the provider withholds). */
    public function payloadIssues(array $payload): array
    {
        return [];
    }

    /** Values for a required enum such as an "intent" parameter, per role. */
    public function preferredEnums(string $role): array
    {
        return [];
    }

    /**
     * How to scope a per-account call for a role: ['param' => name, 'by' => 'id'|'name', 'kinds' => list|null], or null for one call.
     * Default: a tool with an account_id/portfolio_id parameter is called once per account.
     */
    public function perAccount(string $role, array $schema): ?array
    {
        $key = \App\Services\Integrations\Args::parentKey($schema);

        return $key ? ['param' => $key, 'by' => 'id', 'kinds' => null] : null;
    }

    /** Split or filter raw holding rows for the account a call was scoped to ($owner external id, or null). */
    public function expandHoldings(array $rows, ?string $owner): array
    {
        return $rows;
    }

    public function account(array $r): ?array
    {
        $id = Shape::str($r, ['id', 'account_id', 'portfolio_id', 'uuid'], 191);
        $name = Shape::str($r, ['display_name', 'name', 'account_name', 'title', 'nickname']);
        if (! $id || ! $name) {
            return null;
        }
        $type = Shape::str($r, ['type.name', 'type', 'account_type', 'kind', 'category']);
        $subtype = Shape::str($r, ['subtype.name', 'subtype', 'account_subtype']);
        $balance = Shape::dec($r, ['current_balance', 'display_balance', 'balance', 'value', 'market_value', 'total_value', 'current_value', 'net_value']);
        $liability = $this->isLiability($type, $subtype, $r);
        $mask = Shape::str($r, ['mask', 'last4', 'last_four', 'account_mask', 'number_mask'], 16);

        return [
            'external_id' => $id, 'name' => $name,
            'institution' => Shape::str($r, ['institution.name', 'institution_name', 'institution', 'credential.institution.name', 'broker.name', 'broker', 'provider']),
            'kind' => $this->kind($type, $subtype), 'kind_raw' => trim(($type ?? '').' '.($subtype ?? '')) ?: null,
            'mask' => $mask && preg_match('/(\d{2,4})$/', $mask, $m) ? $m[1] : null,
            'balance' => $balance !== null && $liability && ! str_starts_with($balance, '-') ? '-'.$balance : $balance,
            'currency' => strtoupper(Shape::str($r, ['currency', 'currency_code', 'base_currency'], 3) ?? 'USD'),
            'as_of' => Shape::date($r, ['updated_at', 'display_last_updated_at', 'balance_date', 'as_of', 'last_updated']),
            'is_liability' => $liability,
            'closed' => (bool) (Shape::pick($r, ['deactivated_at', 'closed_at', 'is_closed']) ?? false),
        ];
    }

    public function holding(array $r): ?array
    {
        $symbol = Shape::str($r, ['security.ticker', 'ticker', 'symbol', 'asset.symbol', 'asset.ticker', 'instrument.symbol', 'security.symbol'], 32);
        $name = Shape::str($r, ['security.name', 'name', 'asset.name', 'description', 'instrument.name', 'title']);
        $qty = Shape::dec($r, ['quantity', 'shares', 'units', 'amount_of_shares', 'qty']);
        $type = strtolower((string) Shape::str($r, ['security.type', 'security_type', 'type', 'asset.type', 'asset_type', 'kind']));
        if (! $symbol && $name && preg_match('/\bcash\b|money market|sweep/i', $name)) {
            $symbol = 'CASH';
            $type = 'cash';
        }
        if (! $symbol || $qty === null) {
            return null;
        }

        return [
            'symbol' => strtoupper($symbol), 'name' => $name, 'quantity' => $qty,
            'price' => Shape::dec($r, ['price', 'current_price', 'security.current_price', 'last_price', 'close_price']),
            'market_value' => Shape::dec($r, ['total_value', 'market_value', 'value', 'current_value', 'marketValue']),
            'cost_basis' => Shape::dec($r, ['basis', 'cost_basis', 'cost', 'invested', 'total_cost', 'book_value']),
            'currency' => strtoupper(Shape::str($r, ['currency', 'currency_code'], 3) ?? 'USD'),
            'instrument_kind' => match (true) {
                $symbol === 'CASH' || str_contains($type, 'cash') => 'cash',
                str_contains($type, 'etf') => 'etf',
                str_contains($type, 'mutual') || str_contains($type, 'fund') => 'mutual_fund',
                str_contains($type, 'bond') || str_contains($type, 'fixed') => 'bond',
                str_contains($type, 'crypto') => 'crypto',
                str_contains($type, 'option') => 'option',
                default => 'equity',
            },
            'account_ref' => Shape::str($r, ['account.id', 'account_id', 'portfolio_id', 'portfolio.id', 'holding_account_id'], 191),
        ];
    }

    public function transaction(array $r): ?array
    {
        $id = Shape::str($r, ['id', 'transaction_id', 'uuid', 'external_id'], 191);
        $date = Shape::date($r, ['date', 'posted_on', 'posted_date', 'transaction_date', 'trade_date', 'executed_at']);
        $amount = Shape::dec($r, ['amount', 'net_amount', 'total', 'value']);
        if (! $date || $amount === null) {
            return null;
        }
        $type = strtolower((string) Shape::str($r, ['event', 'type', 'operation', 'action', 'transaction_type', 'kind']));
        $kind = match (true) {
            (bool) preg_match('/buy|purchase/', $type) => 'buy',
            (bool) preg_match('/sell|sale/', $type) => 'sell',
            (bool) preg_match('/reinvest/', $type) => 'reinvest',
            (bool) preg_match('/dividend|distribution/', $type) => 'dividend',
            (bool) preg_match('/interest/', $type) => 'interest',
            (bool) preg_match('/fee|commission/', $type) => 'fee',
            (bool) preg_match('/transfer|cash_in|cash_out|deposit|withdraw/', $type) => 'transfer',
            (bool) preg_match('/split/', $type) => 'split',
            (bool) Shape::pick($r, ['is_transfer', 'category.is_transfer']) => 'transfer',
            str_starts_with($amount, '-') => 'spend',
            default => 'income',
        };

        return [
            'external_id' => $id, 'posted_on' => $date, 'amount' => $amount, 'kind' => $kind,
            'currency' => strtoupper(Shape::str($r, ['currency', 'currency_code'], 3) ?? 'USD'),
            'description' => Shape::str($r, ['original_statement', 'plaid_name', 'description', 'name', 'memo', 'note']),
            'merchant' => Shape::str($r, ['merchant.name', 'merchant_name', 'merchant', 'payee']),
            'category' => Shape::str($r, ['category.name', 'category_name', 'category'], 96),
            'notes' => Shape::str($r, ['notes', 'note', 'comment'], 2000),
            'symbol' => ($s = Shape::str($r, ['symbol', 'ticker', 'security.ticker', 'asset.symbol'], 32)) ? strtoupper($s) : null,
            'quantity' => Shape::dec($r, ['quantity', 'shares', 'units']),
            'price' => Shape::dec($r, ['price', 'unit_price']),
            'fees' => Shape::dec($r, ['fee', 'fees', 'commission']),
            'account_ref' => Shape::str($r, ['account.id', 'account_id', 'portfolio_id', 'portfolio.id'], 191),
            'pending' => (bool) (Shape::pick($r, ['pending', 'is_pending']) ?? false),
        ];
    }

    protected function isLiability(?string $type, ?string $subtype, array $r): bool
    {
        $asset = Shape::pick($r, ['is_asset']);
        if (is_bool($asset)) {
            return ! $asset;
        }

        return (bool) preg_match('/credit|loan|mortgage|liabilit|debt|heloc/i', ($type ?? '').' '.($subtype ?? ''));
    }

    protected function kind(?string $type, ?string $subtype): string
    {
        $s = strtolower(($subtype ?? '').' '.($type ?? ''));

        return match (true) {
            str_contains($s, 'roth') && str_contains($s, '401') => 'roth_401k',
            str_contains($s, 'roth') => 'roth_ira',
            str_contains($s, 'sep') => 'sep_ira',
            str_contains($s, '401') => '401k',
            str_contains($s, '403') => '403b',
            str_contains($s, '457') => '457b',
            str_contains($s, 'ira') => 'ira',
            str_contains($s, 'hsa') || str_contains($s, 'health savings') => 'hsa',
            str_contains($s, '529') => '529',
            str_contains($s, 'utma') || str_contains($s, 'ugma') => 'utma',
            str_contains($s, 'crypto') => 'crypto',
            str_contains($s, 'mortgage') => 'mortgage',
            str_contains($s, 'heloc') || str_contains($s, 'home equity') => 'heloc',
            str_contains($s, 'line of credit') || str_contains($s, 'construction') => 'loan',
            str_contains($s, 'credit') => 'credit_card',
            str_contains($s, 'loan') || str_contains($s, 'student') || str_contains($s, 'auto') => 'loan',
            str_contains($s, 'money market') => 'money_market',
            str_contains($s, 'cd') && ! str_contains($s, 'card') => 'cd',
            str_contains($s, 'saving') => 'savings',
            str_contains($s, 'checking') || str_contains($s, 'depository') || str_contains($s, 'cash') => 'checking',
            str_contains($s, 'brokerage') || str_contains($s, 'invest') || str_contains($s, 'portfolio') || str_contains($s, 'stock plan') => 'brokerage',
            default => 'other',
        };
    }
}
