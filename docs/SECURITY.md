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
| Privileged action by hijacked session | Fresh central revalidation + TOTP step-up (10 min) for grants, invites, approvals, exports, legal hold, purge | `…privileged_actions_require_step_up` |
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
- MFA at McNutt Cloud is not asserted in the SSO payload, hence the app's own TOTP step-up.
- Backups exclude `legacy.env` by design; losing the escrowed env makes vault files unrecoverable.
