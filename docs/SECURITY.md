# Security & threat model

## Assets
Family financial records, tax documents, identity fields (SSN last-4, contacts, addresses), estate plans, vault files,
access grants, OAuth tokens, `APP_KEY`, `VAULT_ROOT_KEY`, backup key, DB credentials.

## Actors and trust boundaries
| Actor | Trust | Controls |
| --- | --- | --- |
| Family member (principal/member/dependent) | Authenticated, least privilege | Grants per scope/domain/action/year; redaction of sensitive fields |
| Administrator | Manages membership, **no implicit data access** | Step-up for access changes; audited |
| Advisor (CPA, attorney, broker) | Time-boxed | Grants must expire; year-limited tax grants; export links with access code |
| Connected app (ChatGPT) | Delegated, narrow | OAuth scopes ∩ member grants; read summaries; writes are proposals |
| Sibling apps on the host | Untrusted neighbors | Separate Unix user, FPM pool/socket, DB user, 0700/0750 dirs, open_basedir |
| Document content | **Untrusted input** | Never treated as instructions; quarantined until scanned; active PDF content rejected |

## Threats → mitigations
| Threat | Mitigation | Test |
| --- | --- | --- |
| Cross-workspace data access / IDOR | Workspace route binding to active membership; composite FKs; `constrain()` everywhere; 404 for invisible | `AuthorizationTest::test_workspaces_are_isolated…` |
| Over-broad family visibility | Deny-by-default grants; admins get nothing implicitly; expiry; steward approval | `…administrators_get_no_implicit…`, `…expired_grants…` |
| Sensitive field leakage via list/aggregate/export | Registry `sensitive` redaction; aggregates computed over constrained rows; report redaction options | `…redact_sensitive_fields` |
| Session theft / stale SSO | `__Host-` cookie, Secure, HttpOnly, SameSite=Lax, encrypted session; 5-min revalidation; 12 h absolute; fail closed | — |
| Privileged action by hijacked session | Fresh central revalidation + TOTP step-up (once per sign-in session by owner decision, bounded by the 60-minute idle and 12-hour absolute session limits; set `STEPUP_TTL_SECONDS` to re-ask more often) for grants, invites, approvals, exports, legal hold, purge | `…privileged_actions_require_step_up` |
| CSRF | Laravel CSRF on web + API (same-origin header); OAuth/MCP endpoints use bearer/PKCE instead | — |
| Replay / double submit | Idempotency keys bound to request hash; `If-Match` versions | `IdempotencyAndConcurrencyTest` |
| OAuth code/token theft | PKCE S256 only; 60 s single-use codes; audience-bound tokens; refresh rotation + family revocation on reuse; redirect host allow-list | `OAuthMcpTest` |
| Prompt injection via documents/MCP | Extraction uses strict JSON schema; outputs are drafts needing human approval; MCP returns structured summaries only | — |
| Malicious uploads | MIME sniffing allow-list, PDF active-content rejection, pixel/page limits, macro workbook rejection, quarantine until ClamAV scan, CSP sandbox on downloads | `VaultTest` |
| Vault compromise at rest | Per-file XChaCha20-Poly1305 with wrapped DEK; key outside DB/backups; integrity hash on decrypt | `VaultTest` |
| Download link leakage | 48-char single-use tokens, 120 s TTL, bound to user, access re-checked at redemption; tokens redacted from Apache logs | `VaultTest` |
| CSV/Excel formula injection in reports | `ReportService::cell` prefixes `= + - @ \t \r` | `SafetyHelpersTest` |
| Runner escape / resource exhaustion | No runner from FPM (`proc_open` disabled); CLI runner `-I`, minimal env, rlimits (CPU, AS, files), job-dir confinement, output cap, timeouts within lease | runner `test_contract.py` |
| Stale authority in background jobs | Initiator re-authorized at execution; suspended members fail closed | `ComputeJobsTest::…fail_closed…` |
| Audit tampering | Hash chain per workspace; append-only usage; metadata sanitized | `…audit_chain…`, `SafetyHelpersTest` |
| Secrets in logs | Query strings never logged by Apache; token paths redacted; audit redaction regex; health masked | `…health_is_masked` |
| Supply chain | Locked composer/npm/python deps; offline wheelhouse with SHA256SUMS; CI audit + secret scan | CI |
| Neighbor app compromise | Separate user/pool/DB user; env 0640 root:mcnutt-legacy; vault 0700; code root-owned (app cannot modify) | `deploy/server/preflight.sh` |

## Known limitations / residual risk
- Audit chain can fork under truly concurrent writes in the same workspace (both link to the same predecessor). Forks are
  detectable; a verifier should treat a fork as "concurrent" not "tampered".
- ClamAV is not installed on the host today; uploads are marked `unscanned` and released. Set `VAULT_REQUIRE_SCAN=true`
  after installing `clamav-daemon`.
- OpenAI extraction is off by default; enabling it sends document images to OpenAI under the workspace's consent.
- Property lookup sends the typed address (or municipality/block/lot) to `geocoding.geo.census.gov` and
  `services2.arcgis.com` (NJOGIS parcels), and to `api.rentcast.io` / `api.bridgedataoutput.com` only when their keys are
  set. Results are cached 24 h; owner names are not stored. Zillow, Realtor.com and the Monmouth/Ocean clerk systems are
  outbound links only (no scraping). Requests are rate-limited (20/min/user) and require real-estate write access.
- MCP integrations (Monarch, Snowball) call only allow-listed hosts (`api.monarch.com`, `snowball-analytics.com`); server URLs
  and every OAuth endpoint the provider advertises are checked against that list, redirects are not followed. Monarch uses
  OAuth 2.1 + PKCE with dynamic client registration and requests only `mcp:read`; Snowball keys are sent only in the
  `X-API-Key` header (the key-in-URL form is rejected). Credentials/tokens are encrypted with `APP_KEY` in
  `source_connections.credential_encrypted`, never returned by the API (only a short hint), and entering or approving them
  requires step-up. Tool output is parsed as data only; nothing is forwarded to a model. Rotating `APP_KEY` requires
  re-entering integration credentials.
- County records (Monmouth): `tax1.co.monmouth.nj.us` record cards and `oprs.co.monmouth.nj.us` index searches, run as a
  background job per property with an identifying user agent and pacing. Robots.txt on the tax-board host is not honored
  by owner decision. Grantor/grantee names are stored as identity-sensitive fields.
- MFA at McNutt Cloud is not asserted in the SSO payload, hence the app's own TOTP step-up.
- Backups exclude `legacy.env` by design; losing the escrowed env makes vault files unrecoverable.
