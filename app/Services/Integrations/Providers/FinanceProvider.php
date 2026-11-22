<?php

namespace App\Services\Integrations\Providers;

final class FinanceProvider extends Provider
{
    public function key(): string
    {
        return 'invoices';
    }

    public function label(): string
    {
        return 'McNutt Finance (side business)';
    }

    public function authKind(): string
    {
        return 'api_key';
    }

    public function transport(): string
    {
        return 'rest';
    }

    public function target(): ?string
    {
        return 'entity';
    }

    public function defaultUrl(): string
    {
        return 'https://invoices.mcnutt.cloud/api/v1/data';
    }

    public function allowedHosts(): array
    {
        return ['invoices.mcnutt.cloud'];
    }

    public function featureFlag(): string
    {
        return 'connector_invoices_api';
    }

    public function instructions(): array
    {
        return [
            'summary' => 'Read-only Data API of McNutt Finance for one business: chart of accounts, cash-basis income and expenses, profit & loss and the Finance tax estimate. One key per business.',
            'steps' => [
                'Sign in to invoices.mcnutt.cloud, open the business → Settings → Data API access.',
                'Name the key "McNutt Legacy" and tick ledger.read and reports.read (invoices.read, payments.read and expenses.read are optional). Create it and copy the fin_… key — it is shown once.',
                'Here, choose the business entity this books feed (or let Legacy create it from the Finance profile), paste the key and click Save key.',
                'Click Test to see the business and its datasets, then Preview to review how each account maps to Schedule C lines and compare the Finance P&L, then Approve & enable sync.',
                'Record who owns the business (People & entities → the business → Owners) so the profit lands on the right person\'s Schedule C and SE tax.',
            ],
            'links' => [['label' => 'McNutt Finance', 'url' => 'https://invoices.mcnutt.cloud/']],
            'notes' => [
                'Cash-basis income and expenses become the business\'s Schedule C for single-member LLCs and sole proprietorships; S-corporations and partnerships still need the K-1.',
                'When books are connected for a year, transactions tagged to the same business in Monarch are not counted again for that year.',
                'Meals are 50% deductible; owner draws, distributions and income-tax payments are excluded. Adjust any account\'s Schedule C line in the mapping.',
                'The Finance tax estimate is shown for comparison only; Legacy\'s tax engine remains authoritative.',
            ],
        ];
    }

    protected function toolPatterns(): array
    {
        return [];
    }
}
