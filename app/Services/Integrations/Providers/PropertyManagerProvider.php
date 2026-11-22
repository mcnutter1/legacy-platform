<?php

namespace App\Services\Integrations\Providers;

final class PropertyManagerProvider extends Provider
{
    public function key(): string
    {
        return 'property_manager';
    }

    public function label(): string
    {
        return 'Beach House PMS';
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
        return 'property';
    }

    public function defaultUrl(): string
    {
        return 'https://beachdaysalways.com/api/family-office.php';
    }

    public function allowedHosts(): array
    {
        return ['beachdaysalways.com'];
    }

    public function featureFlag(): string
    {
        return 'connector_property_api';
    }

    public function instructions(): array
    {
        return [
            'summary' => 'Read-only Family Office API of the beach-house property-management system: bookings, owner blocks, refunds, fees, taxes collected, expenses and IRC 280A day counts. No guest names or contact details.',
            'steps' => [
                'In the PMS admin open Settings → Family Office API (run migration 037_family_office_api.sql first if the tab is missing).',
                'Add this server\'s egress IP 34.234.75.3 to the IP allow-list, tick Enable and save.',
                'Click Generate key and copy it (fo_…) — it is shown once.',
                'Here, choose which property the PMS feeds, paste the key and click Save key. Use the apex address https://beachdaysalways.com/… (www. drops the key header).',
                'Click Test, then Preview to compare the PMS profit & loss and day counts, then Approve & enable sync. Stays and expenses land on the property; reclassify family or donated stays there — your edits survive re-syncs.',
            ],
            'links' => [['label' => 'PMS admin', 'url' => 'https://beachdaysalways.com/admin/']],
            'notes' => [
                'Expenses marked "undetermined" (home improvements, mortgage payments, other) need a repair-vs-improvement decision before the tax return is calculated.',
                'Expenses the PMS imported from Monarch are linked to the matching Monarch transaction instead of counted twice.',
                'Bookings that are approved but not yet confirmed/paid are not imported until they become revenue, matching the PMS profit & loss.',
            ],
        ];
    }

    protected function toolPatterns(): array
    {
        return [];
    }
}
