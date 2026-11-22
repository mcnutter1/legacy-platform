---
applyTo: "app/Support/AccessContext.php,app/Services/AccessService.php,app/Resources/**,app/Http/Middleware/**,app/Http/Controllers/Api/AccessController.php,app/Models/**,database/migrations/**"
---
# Authorization rules

- Model: workspace → scopes (joint, private, branch, entity, shared, advisor) with stewards; access only via
  `access_grants` (membership × scope × domains × actions × optional years × expiry, optional steward approval).
  Roles (administrator, principal, member, advisor, dependent) manage membership, never data visibility.
- Every read/write path uses `AccessContext::constrain($query, $domain, $action, $yearColumn)` or `ResourceService`.
  Raw `DB::table()` reads of scoped tables without `constrain` are a bug unless the row was already authorized.
- Sensitive fields (`Registry` `sensitive` map) are redacted to `null` and listed in `_redacted` when the caller lacks the
  sensitivity domain (e.g. `identity`). Aggregates (net worth, reports, MCP) must only sum visible rows.
- Cross-workspace references are impossible by construction: composite `(workspace_id, id)` foreign keys + `assertRefs`.
- Not visible ⇒ 404 `not_found` (no existence oracle). Visible but not permitted action ⇒ 403.
- Access changes, approvals, exports and sharing require `privileged()` (fresh central session + TOTP step-up) and are audited.
- Jobs re-check the initiator's access at execution time (`JobHandler::authorization`); OAuth tokens intersect token scopes
  with the member's grants (`AccessContext` token domains/actions).
- Add a feature test in `tests/Feature/AuthorizationTest.php` for any new domain, scope kind or sensitive field.
