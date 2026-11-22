<?php

namespace App\Services\Integrations\Providers;

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
            'holdings' => ['/^(get_|list_)?(account_)?holdings$/i', '/holding/i', '/positions?/i'],
            'transactions' => ['/^(get_|list_|search_)?transactions$/i', '/^(get|list|search)_?transactions?/i', '/transactions?$/i'],
        ];
    }
}
