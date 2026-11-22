# Changelog

## Unreleased — combined accounts with several sources

### Implemented
- An account linked to several sources (e.g. Monarch and Snowball) takes each kind of data — balance, holdings,
  transactions — from one source. Default: the primary source supplies what it reports and another linked source fills in
  holdings or transactions the primary lacks; each can be chosen explicitly on the account's Sources tab.
- Same-day positions from a different source than the balance no longer override the reported balance in net worth.
- "Combine another account into this one" on the account's Sources & duplicates tab links any two accounts manually.

## Unreleased — paystub values from assistants

### Implemented
- MCP tool `submit_paystub_values`: an assistant that can read the paystub (e.g. Claude) submits pay date, period and
  current/YTD amounts; Legacy runs the payroll checks and creates a review draft. Optional paper copy as base64, via a
  24-hour link, or attached on the new draft page (`extractions/{id}`). Approval still happens only in the app.

## Unreleased — hide, ignore and de-duplicate accounts

### Implemented
- Accounts can be **hidden** (still synced, left out of net worth, investments, planning, estate, tax strategies, MCP and
  the default accounts list) or **ignored** (additionally, no source writes balances, holdings or transactions to it and
  sync never re-creates it). Reason recorded and audited; restore at any time.
- Duplicate finder (Accounts › Find duplicates, and per account under Sources & duplicates): scores existing account
  pairs on mask, institution, name, type and balance; merge either way, mark "not duplicates" (remembered), or — when one
  source reports both, e.g. an aggregate — ignore one.

## Unreleased — schedules

### Fixed
- Approved connections never synced on their own: a preview reset the scheduler clock, REST connections were excluded
  and connections in `error` were never retried. Scheduling now uses `next_sync_at`; approval queues the first sync;
  failures retry after an hour. The scheduler checks every 15 minutes (was hourly).

### Implemented
- Admin › Schedules: every data-source sync (interval, next run, last success, Run now), on-demand county-record pulls,
  system cron tasks with heartbeats, and run history with full output (parameters, timing, attempts, result, error,
  metrics). Runs are visible to members who can read the connection or property.

## Unreleased — side-business and rental ingest

### Implemented
- Integrations: **Beach House PMS** (Family Office API, `fo_` key) feeds one property's stays and expenses;
  **McNutt Finance** (Data API, `fin_` key, one per business) feeds a business entity's cash-basis books
  (`business_ledger_lines`). Both follow key → Test → Preview → Approve → Sync, run as `integration.sync` jobs and
  reconcile against the provider's own P&L.
- PMS sync upserts by `external_id`, keeps a person's reclassification (family/donated stays, expense category and
  treatment), archives rows deleted in the PMS, records kept damage deposits (`damage_recovery`) and links expenses the
  PMS imported from Monarch to the Monarch transaction.
- Finance sync creates the business entity from the Finance profile when none is chosen, suggests a Schedule C line per
  account (editable), and stores the Finance tax estimate as provisional.
- Tax inputs: Schedule C uses connected books when present for the year (meals 50%, draws and income tax excluded);
  Schedule E rents include kept damage deposits. Sources in `docs/TAX_RULES.md`.
- New page Earnings › Businesses & rentals.

### Verified
- `BusinessAndRentalSourcesTest` with fictional fixtures (idempotent re-sync, user edits kept, deletions, Schedule C and
  Schedule E inputs). Not yet verified against the live PMS/Finance APIs. Runner rules unchanged.

### Not implemented
- Finance accrual journals, invoices, payments and expenses datasets (cash events are sufficient for a cash-basis
  Schedule C); `deletions` (cash events are append-only).

## Unreleased — Monmouth County records

### Implemented
- "Pull county records" (job `property.county_records`): reads the Monmouth tax-board record card (full tax-list history
  of land/improvement/total assessments, last/this-year taxes, SR1A sales with book/page, price, NU code, grantee) and
  looks up each deed/mortgage book & page in the County Clerk OPRS index (instrument number, recording date, parties,
  document type). Manual entries are never overwritten; re-running enriches in place.
- Owner decision: the tax-board host's robots.txt (`Disallow: /`) is not honored. Requests are on demand, one property at a
  time, identified by user agent, and paced.

### Verified
- Parsers checked live against one real card and OPRS book/page search; `CountyRecordsTest` uses fictional fixtures.

### Not implemented
- OPRS document images: the viewer step redirects automated sessions to an error page; images are still attached
  manually. OPRS name and block/lot searches currently fail on the county side (empty municipality/type lists).
- Ocean County: the clerk search is a Windows desktop application; no web interface to read.

## Unreleased — property value estimates

### Implemented
- "Estimate from nearby sales": deterministic comparable-sales indication from the NJ tax list (usable sales within 1.6 km
  in 3 years, median sale ÷ current assessment × subject assessment, quartile range), saved as a proposed valuation.
- "Record an estimate": enter a Zillow/Redfin/Realtor.com/appraisal/broker/own figure with range, date and link; accept
  immediately or keep as a proposal.

### Not implemented (by design)
- Scraping Zillow/Redfin/Realtor.com: their terms prohibit automated collection and they block it. Licensed feeds
  (RentCast key, Zillow via Bridge) remain supported.

## Unreleased — property tax records, recorded documents, joint title

### Implemented
- Property taxes: assessment and levy history per year from the NJ tax list (MOD-IV), NJ quarterly installments
  (projected from last year until the bill is entered), "Record payment" posts a `property_tax` expense used by the tax
  engine, due-date alerts. Deep link to the Monmouth property record card (tax list history) and the Ocean tax board.
- Recorded documents per property (deed, mortgage, satisfaction, lien, easement…) with book/page/instrument number,
  optional link to the secured loan, and "Attach PDF" into the Vault. The deed reference is pre-filled from the tax list.
- Owners editor for properties, assets, accounts and debts: sole, joint tenancy with survivorship, tenancy by the
  entirety (spouses, real property only), tenants in common (any shares), community, trust, entity. Effective-dated;
  prior title is end-dated, not deleted.

### Not implemented (by design)
- Automatic download from the county clerk systems: Ocean County's search is a Windows ClickOnce application with a
  proprietary service and click-through terms, Monmouth OPRS did not respond to automated requests, and the county
  tax-board site disallows automated access (robots.txt). Users download images there and attach them.

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
