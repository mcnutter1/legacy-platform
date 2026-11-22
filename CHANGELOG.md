# Changelog

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
