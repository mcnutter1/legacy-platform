<?php

namespace App\Services\Integrations\Providers;

use App\Services\Integrations\Shape;

final class MonarchProvider extends Provider
{
    public function key(): string
    {
        return 'monarch';
    }

    public function label(): string
    {
        return 'Monarch Money';
    }

    public function authKind(): string
    {
        return 'oauth';
    }

    public function defaultUrl(): string
    {
        return 'https://api.monarch.com/mcp';
    }

    public function allowedHosts(): array
    {
        return ['api.monarch.com'];
    }

    public function featureFlag(): string
    {
        return 'connector_monarch_api';
    }

    public function instructions(): array
    {
        return [
            'summary' => 'Monarch\'s official MCP connector, authorized with OAuth. Legacy asks for read-only access and never sees your Monarch password.',
            'steps' => [
                'Make sure your Monarch plan includes the MCP connector (Core or Plus) and that you can sign in at app.monarch.com.',
                'Here, click "Connect with Monarch". A Monarch window opens: sign in, review the data-provider disclosure, and leave only "Read" checked. Do not grant Write.',
                'If Monarch has not yet approved this site\'s return address, the approval tab ends on a 127.0.0.1 page that will not load — copy that page\'s full address and paste it here to finish. To skip this step later, request approval of the return address in Monarch → Settings → MCP Connector.',
                'You return here automatically. Click "Test" to list the tools Monarch exposes, then "Preview" to see the accounts, balances and holdings that would be imported.',
                'Review how each Monarch account matches your existing accounts (linked, new, or possible duplicate), then click "Approve & enable sync".',
                'To revoke later: in Monarch go to Settings → MCP Connector and remove "McNutt Legacy", or click "Disconnect" here (both stop access immediately).',
            ],
            'links' => [
                ['label' => 'Monarch MCP Connector help', 'url' => 'https://help.monarch.com/hc/en-us/articles/50207234679956-Monarch-MCP-Connector'],
                ['label' => 'Monarch Settings → MCP Connector', 'url' => 'https://app.monarch.com/settings/integrations'],
            ],
            'notes' => [
                'Accounts Monarch connects through Mastercard Connect (Finicity) — often Fidelity — are not available over MCP. Use Plaid/MX in Monarch, or Snowball/CSV for those.',
                'Monarch applies weekly usage limits; scheduled syncs run once a day by default.',
            ],
        ];
    }

    protected function toolPatterns(): array
    {
        return [
            'accounts' => ['/^(get_|list_)?accounts$/i', '/^(get|list)_?accounts?(_list)?$/i', '/accounts?$/i'],
            'holdings' => ['/^(get_|list_)?(account_)?holdings$/i', '/holding/i', '/positions?/i', '/^(get|list)_?investments$/i'],
            'transactions' => ['/^(get_|list_|search_)?transactions$/i', '/^(get|list|search)_?transactions?/i', '/transactions?$/i'],
        ];
    }

    private const INVESTMENT_KINDS = ['brokerage', 'ira', 'roth_ira', 'sep_ira', 'solo_401k', '401k', 'roth_401k', '403b', '457b', 'hsa', '529', 'utma', 'crypto', 'private_fund', 'other'];

    /** Monarch's tools require an "intent" describing the task; these are the honest ones for a read-only sync. */
    public function preferredEnums(string $role): array
    {
        return match ($role) {
            'accounts' => ['net_worth_tracking'],
            'holdings' => ['investment_tracking'],
            default => ['organizing_finances'],
        };
    }

    public function payloadIssues(array $payload): array
    {
        $n = (int) ($payload['finicity_excluded']['hidden_account_count'] ?? 0);
        $inst = array_filter((array) ($payload['finicity_excluded']['institutions'] ?? []), 'is_string');

        return $n > 0 ? ["Monarch withholds {$n} account(s) connected through Mastercard/Finicity".($inst ? ' ('.implode(', ', array_slice($inst, 0, 5)).')' : '')
            .' from connectors; their balances and holdings cannot be synced. Switch them to Plaid/MX in Monarch, or use Snowball or a CSV import.'] : [];
    }

    /** Homes and vehicles Monarch tracks as accounts belong under Real estate / Assets, not accounts. Their loans are kept. */
    public function skipReason(array $row): ?string
    {
        $type = (string) Shape::str($row, ['type.name', 'type', 'subtype.name', 'subtype']);
        $balance = Shape::dec($row, ['balance', 'current_balance', 'display_balance']);
        if (preg_match('/^(primary home|secondary home|home|real estate|property|rental property|car|vehicle|boat|motorcycle|rv|construction)$/i', trim($type))
            && ($balance === null || ! str_starts_with($balance, '-'))) {
            return 'homes and vehicles (track them under Real estate and Assets)';
        }

        return parent::skipReason($row);
    }

    public function account(array $r): ?array
    {
        $a = parent::account($r);
        // Monarch signs balances: negative means money owed.
        if ($a && $a['balance'] !== null && str_starts_with($a['balance'], '-') && ! $a['is_liability']) {
            $a['is_liability'] = true;
            $a['kind'] = $a['kind'] === 'other' ? 'loan' : $a['kind'];
        }

        return $a;
    }

    /** GetInvestments aggregates by security; filter it by account name and keep each account's own share. */
    public function perAccount(string $role, array $schema): ?array
    {
        if ($role === 'holdings' && isset($schema['properties']['accounts'])) {
            return ['param' => 'accounts', 'by' => 'name', 'kinds' => self::INVESTMENT_KINDS];
        }

        return parent::perAccount($role, $schema);
    }

    public function expandHoldings(array $rows, ?string $owner): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (empty($row['ticker']) && strtolower((string) ($row['security_type'] ?? '')) === 'cash') {
                $row['ticker'] = 'CASH';
            }
            $split = is_array($row['account_holdings'] ?? null) ? $row['account_holdings'] : [];
            unset($row['account_holdings']);
            if (! $split) {
                $out[] = $row;

                continue;
            }
            foreach ($split as $part) {
                $acct = is_array($part) ? (string) ($part['account_id'] ?? '') : '';
                if ($acct === '' || ($owner !== null && $acct !== $owner)) {
                    continue;
                }
                $out[] = ['quantity' => $part['quantity'] ?? null, 'value' => $part['value'] ?? null, 'account_id' => $acct] + $row;
            }
        }

        return $out;
    }
}
