# Acceptance coverage matrix

Legend: **V** = verified by an automated test in this repo · **I** = implemented, exercised manually/locally, no dedicated
automated test yet · **P** = partial / gated as unsupported · **—** = not started.

## Release gates (spec §13)

| Gate | Item | Status | Evidence |
| --- | --- | --- | --- |
| 0 Foundation | SSO session + revalidation, fail closed | I | `EnsureCentralAuth`, `CentralSession`; McNutt gateway ported from invoice app |
| 0 | Dev auth refused in production | I | `AppServiceProvider` binding guard |
| 0 | Authorization matrix: cross-workspace denial, admin no implicit access, scope/domain grants, expiry, redaction | V | `tests/Feature/AuthorizationTest.php` |
| 0 | Step-up for privileged actions | V | `AuthorizationTest::test_privileged_actions_require_step_up` |
| 0 | Idempotency, If-Match concurrency, validation of refs across workspaces | V | `tests/Feature/IdempotencyAndConcurrencyTest.php` |
| 0 | Hash-chained, sanitized audit | V | `IdempotencyAndConcurrencyTest`, `tests/Unit/SafetyHelpersTest.php` |
| 0 | Synthetic seed (fictional family) | V | `legacy:demo`, used by `ComputeJobsTest` |
| 0 | MySQL DDL portability (identifier length, FK index prefixes, ordering) | V | `scripts/dev/check_mysql_ddl.py`; CI migrates on MySQL 8 |
| 1 Financial core | Family, ownership (effective-dated fractions), assets, liabilities | I | Registry resources + `OwnershipService::validateAll` |
| 1 | Vault: encryption at rest, quarantine, single-use user-bound downloads, file-type gates, dedupe | V | `tests/Feature/VaultTest.php` |
| 1 | Imports: Monarch preview → review → commit → replay no-op → reverse | V | `tests/Feature/ImportTest.php`, `runners/tests/test_engines.py` |
| 1 | Snowball import, transfers pairing, enrich vs duplicate | I / V | runner tests (normalize); pairing implemented in `ImportService` |
| 1 | Live MCP sources (Monarch OAuth, Snowball key): test, preview, approve, sync, multi-instance, cross-source de-dupe, conflicts, merge, provenance badges | V (faked servers) | `tests/Feature/IntegrationsTest.php`; real tool names/payloads verified only at connect time |
| 1 | Net worth (one count per asset, liabilities, unvalued, contingent excluded) | I | `NetWorthService`; UI Net worth |
| 1 | Year close + wealth bridge with residual, close/reopen versioning | I | demo e2e; `YearCloseService` |
| 2 Tax & earnings | Federal + NJ engine, line map, trace | V | `runners/tests/test_tax.py` (hand-computed MFJ 2025), `ComputeJobsTest` |
| 2 | Status gating (estimate until approved packs; unsupported cases) | V | `ComputeJobsTest::test_tax_run_produces_an_estimate…` |
| 2 | What-if overrides never mutate facts | V | `ComputeJobsTest::test_what_if_overrides…` |
| 2 | Rental §280A / §469 classification | V | `runners/tests/test_tax.py` |
| 2 | Strategies (deferral, HSA, Roth conversion, bunching, harvesting, §179, safe harbor) | V (engine) / I (UI) | `test_tax.py` strategies cases |
| 2 | Payroll extraction → approval with checks → payroll snapshot | I | `PayrollService`, DocumentDetail UI |
| 2 | Equity grants, tranches, events → lots; scenario model | I / V | `runners/tests/test_engines.py` (equity) |
| 2 | Rule-pack approval by named reviewer | I | `TaxController::approvePack` (admin + step-up) |
| 3 Retirement & debt | Deterministic projection, conservation check, sensitivity | V | `runners/tests/test_planning.py`, `ComputeJobsTest` |
| 3 | Monte Carlo reproducibility (seeded PCG64) | V | `test_planning.py`, `ComputeJobsTest` (seed persisted) |
| 3 | Stress scenarios | V | `test_planning.py` |
| 3 | Debt amortization (actual dates, rounding), payoff strategies, refi | V | `runners/tests/test_engines.py` |
| 4 Legal, estate, insurance | Estate distribution: title/beneficiary first, trusts, federal + NJ inheritance, liquidity, first-death fixtures | V | `runners/tests/test_engines.py` (estate) |
| 4 | Insurance gaps/overlaps/umbrella underlying, loss scenario, quote compare | I | `InsuranceService` |
| 4 | Governance prompts, emergency binder break-glass with waiting period/dual approval | I | `GovernanceService` |
| 4 | Report packages (CSV formula-safe, PDF, manifest) + expiring code-protected export links | V / I | `SafetyHelpersTest` (CSV), `ComputeJobsTest` (report ready) |
| 5 ChatGPT & production | OAuth DCR, PKCE S256, code single use, refresh rotation + reuse revocation | V | `tests/Feature/OAuthMcpTest.php` |
| 5 | MCP tools trimmed to scopes, origin check, suspension cuts access | V | `OAuthMcpTest` |
| 5 | Paystub intake via MCP / link → review | I | `IntakeController`, `McpServer::paystubIntake` |
| 5 | Notifications (outbox, dedupe, quiet hours, delivery_unknown) | I | `NotificationService`, `OutboxProcessor` |
| 5 | Jobs re-authorize at execution; fail closed | V | `ComputeJobsTest::test_jobs_fail_closed…` |
| 5 | Deploy: read-only preflight on the real host | V (manual run) | `./deploy.sh preflight` → 31 ok, 0 problems (pre-deploy) |
| 5 | First production deploy: MySQL migrations, TLS, health, auto-revert on failed health, pre-deploy backup, restore drill, sibling apps unaffected | V (manual run 2026-11-22) | `./deploy.sh`, `mcnutt-legacy-restore-check`, HTTPS smoke |
| 5 | McNutt Cloud role mapping (admin/family/ice → membership roles, suspension, pinning, last-admin guard) | V | `tests/Feature/CentralRoleSyncTest.php` |
| 5 | Backup/restore drill, rollback, sibling-app checks on host | I | `deploy/server/*` — to verify at first deploy |

## Frontend
| Item | Status | Evidence |
| --- | --- | --- |
| Strict TypeScript across all pages | V | `npm run typecheck` |
| API client (CSRF, idempotency, If-Match, structured errors) and formatting | V | `resources/js/test/*.test.ts` |
| All 40 routes render without runtime errors against seeded data | V (manual scripted) | Playwright smoke during build-out |
| Accessibility: labels, keyboard dialogs, chart table alternatives | I | Components in `resources/js/components` |

## Not yet automated (next)
Browser E2E in CI (Playwright, mobile + desktop), axe accessibility scans, load test for SLOs, concurrent deploy/run test,
notification provider contract test against notifications.mcnutt.cloud, SSO callback HMAC fixtures from McNutt Cloud.
