# Changelog

## Unreleased — live Monarch and Snowball integrations

### Implemented
- Wealth → Integrations: multiple connections per provider, each with its own scope, credentials, tool mapping and schedule.
  Monarch via its official MCP server (OAuth 2.1, PKCE, dynamic registration, read-only); Snowball Analytics via its MCP
  server and a personal key. Per-provider instructions for what to do in the third-party app.
- Test (tools/list) → preview (dry run, no writes) → approve → sync (`integration.sync` job, hourly scheduler).
- Cross-source de-dupe: accounts are matched by external id, mask, institution, name, type and balance; confident matches
  link to the existing account, ambiguous ones are created and flagged as possible duplicates with a merge action.
  One authoritative source per account writes balances/holdings/transactions; other sources are compared and
  disagreements (balance, holding quantity, missing position) become source conflicts that can be ignored or resolved by
  trusting a source.
- Provenance: accounts, holdings, balances, transactions and lots carry `_sources`; tables and account pages show
  source icons (authoritative outlined). Account → Sources tab shows what each source last reported.
- Synced transactions are staged as import batches, so review/commit/reverse and fingerprint/enrich de-dupe apply.

### Verified
- `IntegrationsTest` (4 tests) against faked Monarch/Snowball MCP servers (JSON and SSE responses).

### Not verified
- Real tool names and payload shapes for both providers: discovered on Test and shown for approval before any write;
  field parsing is schema-tolerant but untested against live accounts.

## Unreleased — property records and secured loans

### Implemented
- Add-by-address property lookup: US Census address validation, NJ MOD-IV parcel match (block, lot, qualifier, PAMS PIN,
  class, assessed values, last-year tax, deed book/page/date, last sale, year built), ranked candidates, or search by
  municipal code + block + lot. Links to Zillow, Realtor.com, NJ tax-assessment search and the Monmouth (OPRS) /
  Ocean County clerk record searches.
- Applying records stores block/lot and parcel facts on the property, adds an accepted tax-assessment valuation and
  optional licensed AVMs (RentCast, Zillow via Bridge) as *proposed* valuations; lookup history is kept.
- Valuation review (accept/reject proposed valuations).
- Loans linked to homes, vehicles and boats (`collateral_type`/`collateral_id`) with a financing view: value, secured
  debt, equity and loan-to-value. New asset detail page; vehicle make/model/year/registration fields.

### Verified
- `PropertyRecordsTest` (5 tests, faked providers). Live lookup against Census + NJOGIS after deploy.

### Not verified
- Bridge (Zillow) response parsing — no agreement/token available. RentCast parsing verified only against its docs.

## 1.0.0 — initial platform (unreleased)

### Implemented
- Laravel 12 API + React 19 SPA: home, net worth, accounts, investments, real estate, assets, debts, imports, year close,
  payroll, equity, tax (units, runs with trace, what-if, strategies, estimated payments, rule packs), planning (scenarios,
  projections, Monte Carlo, stress, comparisons, spending baseline), estate, insurance, family & entities, governance,
  vault, reports & sharing, access, settings, activity, notifications, search, all-records browser.
- Deny-by-default access model (scopes × domains × actions × years, expiry, steward approval), TOTP step-up.
- Python calculators: federal + NJ tax (2025, 2026 packs), rental classification, strategies, planning engine,
  debt, estate, equity, imports, document text extraction.
- Encrypted vault, leased compute jobs, transactional outbox, notifications, hash-chained audit.
- MCP connector with OAuth 2.1 (DCR, PKCE, rotating refresh).
- Release-based deployment for the shared host (preflight, install, rollback, status, backup, restore drill).

### Verified
- 30 PHP feature/unit tests, runner pytest suite, 8 Vitest tests, strict TypeScript, MySQL DDL static check,
  read-only production preflight (0 problems). See [docs/ACCEPTANCE.md](docs/ACCEPTANCE.md).

### Not verified yet
- Tax rule packs await named professional approval (all runs are "estimate" until then).
- First deployment, TLS issuance, SSO registration, notification token, backup/restore on the host.
