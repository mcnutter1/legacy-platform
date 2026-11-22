<?php

namespace App\Services\Integrations\Providers;

use App\Services\Integrations\Shape;

final class SnowballProvider extends Provider
{
    public function key(): string
    {
        return 'snowball';
    }

    public function label(): string
    {
        return 'Snowball Analytics';
    }

    public function authKind(): string
    {
        return 'api_key';
    }

    public function defaultUrl(): string
    {
        return 'https://snowball-analytics.com/mcp-server';
    }

    public function allowedHosts(): array
    {
        return ['snowball-analytics.com'];
    }

    public function featureFlag(): string
    {
        return 'connector_snowball_api';
    }

    public function instructions(): array
    {
        return [
            'summary' => 'Snowball Analytics\' read-only MCP server, authorized with a personal MCP key (Expert plan).',
            'steps' => [
                'Sign in to Snowball Analytics and open your Profile → MCP tab (snowball-analytics.com/profile#mcp). MCP requires the Expert plan.',
                'Click "Generate". Copy the full key immediately — Snowball shows it only once — and note the server address shown on the same tab.',
                'Here, paste the key (and the server address if it differs from the default) and click "Save key". The key is encrypted at rest and never shown again; only its prefix is kept.',
                'Click "Test" to list Snowball\'s tools, then "Preview" to see the portfolios and holdings that would be imported and how they match accounts you already have (for example the same brokerage account seen through Monarch).',
                'Approve the mapping to enable sync. To revoke, use Regenerate or Delete on Snowball\'s MCP tab, or click "Disconnect" here.',
            ],
            'links' => [
                ['label' => 'How to connect Snowball over MCP', 'url' => 'https://help.snowball-analytics.com/how-to-connect-snowball-analytics-to-claude-cursor-and-other-ai-apps/'],
                ['label' => 'Snowball profile (MCP tab)', 'url' => 'https://snowball-analytics.com/profile#mcp'],
            ],
            'notes' => [
                'The key is sent only as the X-API-Key header, never in a URL.',
                'The combined/aggregate portfolio is skipped so holdings are not counted twice.',
                'Snowball is a tracker: for cost-basis disputes, custodian statements take priority.',
            ],
        ];
    }

    public function accountKeys(): array
    {
        return ['portfolios', 'accounts', 'items', 'data', 'results'];
    }

    public function isAggregate(array $row): bool
    {
        $flag = Shape::pick($row, ['is_combined', 'combined', 'is_aggregate', 'aggregate', 'is_total']);
        if ($flag) {
            return true;
        }
        $name = (string) Shape::str($row, ['name', 'title']);

        return (bool) preg_match('/^(combined|all portfolios|total)\b/i', $name);
    }

    public function account(array $r): ?array
    {
        $a = parent::account($r);
        if ($a && $a['kind'] === 'other') {
            $a['kind'] = 'brokerage';
        }

        return $a;
    }

    protected function toolPatterns(): array
    {
        return [
            'accounts' => ['/^(get_|list_)?portfolios$/i', '/portfolios?(_list)?$/i', '/accounts?$/i'],
            'holdings' => ['/^(get_|list_)?(portfolio_)?holdings$/i', '/holding/i', '/positions?/i'],
            'transactions' => ['/^(get_|list_)?(portfolio_)?transactions$/i', '/transactions?$/i', '/trades?$/i'],
        ];
    }
}
