/** Map a resource type + id to the best in-app route (relative to the workspace). */
const DETAIL: Record<string, (id: string) => string> = {
  accounts: (id) => `accounts/${id}`,
  properties: (id) => `real-estate/${id}`,
  equity_grants: (id) => `equity/${id}`,
  tax_units: (id) => `tax/units/${id}`,
  tax_runs: (id) => `tax/runs/${id}`,
  tax_run: (id) => `tax/runs/${id}`,
  plan_scenarios: (id) => `planning/scenarios/${id}`,
  plan_runs: (id) => `planning/runs/${id}`,
  plan_run: (id) => `planning/runs/${id}`,
  estate_runs: (id) => `estate/runs/${id}`,
  estate_run: (id) => `estate/runs/${id}`,
  documents: (id) => `vault/${id}`,
  document: (id) => `vault/${id}`,
  document_extraction: (id) => `extractions/${id}`,
  document_extractions: (id) => `extractions/${id}`,
  import_batches: (id) => `imports/${id}`,
  import_batch: (id) => `imports/${id}`,
};

const SECTION: Record<string, string> = {
  transactions: 'accounts', holdings: 'portfolio', lots: 'portfolio', instruments: 'portfolio', balance_snapshots: 'accounts',
  assets: 'assets', valuations: 'assets', liabilities: 'debts', liability_payments: 'debts',
  employments: 'payroll', payroll_snapshots: 'payroll', business_ledger_lines: 'business-income', equity_tranches: 'equity', equity_events: 'equity', company_valuations: 'equity',
  tax_documents: 'tax', tax_residencies: 'tax', tax_strategies: 'tax',
  plans: 'planning', family_goals: 'planning',
  estate_documents: 'estate', estate_scenarios: 'estate', beneficiary_designations: 'estate', authority_roles: 'estate', trust_details: 'estate',
  insurance_policies: 'insurance', policy_coverages: 'insurance', insurance_claims: 'insurance', insurance_shopping_cases: 'insurance', insurance_quotes: 'insurance',
  persons: 'family', relationships: 'family', households: 'family', legal_entities: 'family', contacts: 'family', ownership_interests: 'family',
  decisions: 'governance', meetings: 'governance', tasks: 'governance', emergency_binders: 'governance',
  report_manifests: 'reports', annual_snapshots: 'year-close',
};

export function recordPath(type: string, id: string): string {
  const d = DETAIL[type];
  if (d) return d(id);
  return SECTION[type] ?? `records/${type}`;
}
