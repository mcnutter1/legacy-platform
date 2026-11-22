import type { ResourceType, ResourceTypes } from './generated';

export interface Me {
  user: { id: string; name: string | null; email: string | null; stepup_enrolled: boolean; can_create_workspaces: boolean };
  stepup: { required: boolean; fresh: boolean };
  workspaces: { id: string; name: string; role: string; status: string; membership_id: string; timezone: string; base_currency: string }[];
  domains: Record<string, string>;
  release: string;
}

export interface FieldMeta {
  name: string;
  type: string;
  required: boolean;
  readonly: boolean;
  values: string[] | null;
  ref: string | null;
  poly_type_field: string | null;
  sensitive: string | null;
}

export interface ResourceMeta {
  type: ResourceType;
  label: string;
  domain: string;
  title: string | null;
  list: string[];
  readonly: boolean;
  creatable: boolean;
  parent: string | null;
  unscoped: boolean;
  searchable: boolean;
  date: string | null;
  fields: FieldMeta[];
}

export interface Meta {
  resources: Record<string, ResourceMeta>;
  domains: Record<string, string>;
  actions: string[];
}

export type AnyRecord = ResourceTypes[ResourceType] & Record<string, unknown>;

export interface Job {
  id: string;
  type: string;
  queue: string;
  status: 'queued' | 'leased' | 'succeeded' | 'failed' | 'timed_out' | 'cancelled' | 'retryable';
  attempts: number;
  result: Record<string, unknown> | null;
  error: string | null;
  created_at: string | null;
  finished_at: string | null;
}

export interface NetWorthItem {
  section: 'assets' | 'liabilities';
  category: string;
  label: string;
  type: string;
  id: string;
  value: string;
  share: string;
  method: string;
  as_of: string | null;
  liquid: boolean;
}

export interface NetWorth {
  as_of: string;
  view: string;
  currency: string;
  total_assets: string;
  total_liabilities: string;
  net_worth: string;
  liquid_assets: string;
  categories: { section: string; category: string; value: string; items: NetWorthItem[] }[];
  unvalued: { category: string; label: string; type: string; id: string }[];
  contingent_liabilities: { label: string; amount: string | null; kind: string }[];
  equity_compensation: { grant_id: string; company: string; type: string; vested_units: string; price: string | null; price_as_of: string | null; intrinsic_value: string | null }[];
  issues: { code: string; message: string }[];
  methodology: string;
}

export interface Scope {
  id: string;
  name: string;
  kind: string;
  description: string | null;
  stewards: string[];
  my_access: Record<string, string[]>;
}

export interface AccessOverview {
  me: { membership_id: string; role: string; is_administrator: boolean; steward_of: string[] };
  members: { id: string; role: string; status: string; label: string | null; person_id: string | null; name: string | null; email: string | null; expires_at: string | null; is_recovery_admin: boolean }[];
  scopes: Scope[];
  grants: { id: string; membership_id: string; scope_id: string; domains: string[]; actions: string[]; years: number[] | null; purpose: string | null; expires_at: string | null; pending_approval: boolean; granted_by: string }[];
}

export interface TaxLine {
  jurisdiction: string;
  form: string;
  line: string;
  label: string;
  value: string;
  node: string;
  rule: string | null;
}

export interface TaxRun {
  id: string;
  tax_unit_id: string;
  tax_year: number;
  scenario: string;
  kind: string;
  status: string;
  summary: Record<string, Record<string, unknown>> & { status_reasons?: string[]; rentals?: unknown[] };
  lines: TaxLine[] | null;
  issues: { jurisdiction?: string; code: string; severity: string; message: string }[] | null;
  rule_packs: Record<string, { version: string; hash: string; approved: boolean; legislation_status: string }> | null;
  engine_version: string | null;
  input_hash: string | null;
  reconciliation: { lines: { line: string; filed: string; calculated: string | null; difference: number | null; match: boolean }[]; all_match: boolean } | null;
  reviewer_name: string | null;
  reviewed_at: string | null;
  immutable: boolean;
  created_at: string;
}

export interface TraceNode {
  node: string;
  value: string;
  rule: string | null;
  inputs: Record<string, string>;
  note?: string;
  facts?: string[];
  jurisdiction: string;
}
