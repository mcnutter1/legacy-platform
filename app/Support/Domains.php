<?php

namespace App\Support;

/** Data classification domains used by grants, policies, MCP scopes and reports. */
final class Domains
{
    public const ALL = [
        'household', 'identity', 'ledger', 'portfolio', 'compensation', 'real_estate', 'assets', 'debt',
        'tax', 'planning', 'estate', 'insurance', 'vault', 'governance', 'reports',
    ];

    public const ACTIONS = ['read', 'write', 'approve', 'export'];

    public const LABELS = [
        'household' => 'Family & households',
        'identity' => 'Identity details',
        'ledger' => 'Accounts & transactions',
        'portfolio' => 'Investments',
        'compensation' => 'Payroll & equity',
        'real_estate' => 'Real estate',
        'assets' => 'Personal assets',
        'debt' => 'Debts',
        'tax' => 'Tax',
        'planning' => 'Plans & scenarios',
        'estate' => 'Estate & beneficiaries',
        'insurance' => 'Insurance',
        'vault' => 'Documents',
        'governance' => 'Governance & contacts',
        'reports' => 'Reports & exports',
    ];

    /** Broker intake default: never tax, estate/beneficiary or identity. */
    public const BROKER_DEFAULT = ['insurance', 'assets', 'real_estate'];

    /** Domains a report depends on — report access requires all of them. */
    public const REPORT_DEPENDENCIES = [
        'cpa_package' => ['tax', 'compensation', 'ledger', 'real_estate', 'portfolio'],
        'insurer_package' => ['insurance', 'assets', 'real_estate'],
        'emergency_binder' => ['governance', 'insurance', 'estate'],
        'counsel_binder' => ['estate', 'household'],
        'wealth_bridge' => ['ledger', 'portfolio', 'debt', 'real_estate', 'assets'],
        'retirement_report' => ['planning'],
        'beneficiary_report' => ['estate'],
        'net_worth' => ['ledger', 'portfolio', 'debt', 'real_estate', 'assets'],
    ];

    public static function valid(string $domain): bool
    {
        return in_array($domain, self::ALL, true);
    }
}
