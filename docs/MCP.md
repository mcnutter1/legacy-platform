# MCP connector (ChatGPT)

Endpoint: `https://legacy.mcnutt.cloud/mcp` (Streamable HTTP, JSON responses). Discovery:
`/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`.

Connect: ChatGPT → Settings → Connectors → Add custom connector → URL above → sign in with McNutt Cloud → confirm with your
authenticator → choose the workspace and permissions. Disconnect any time in Legacy → Settings → Privacy & apps.

## Scopes
| Scope | Grants (intersected with your own access) |
| --- | --- |
| `wealth.read` | Net worth, accounts and balances (ledger, portfolio, debt, real estate, assets, insurance — read) |
| `investments.read` | Positions and allocation |
| `plans.read` / `plans.run` | Plan summaries / run saved scenarios (results are projections) |
| `tax.read` | Tax estimates and run status |
| `docs.search` | Document titles and *reviewed* fields only (never raw text) |
| `payroll.intake.write` | Submit paystubs for your review |
| `records.propose` | Propose changes that land in the Review inbox |
| `alerts.read` | Reminders and due dates |

## Tools
`get_net_worth`, `list_accounts`, `get_portfolio`, `get_tax_estimate`, `get_plan_summary`, `run_plan_scenario`,
`get_job_status`, `search_documents`, `list_alerts`, `list_employments`, `start_paystub_intake`, `get_intake_status`,
`propose_record_change`. Tools outside the granted scopes are not listed and cannot be called. Every call is audited.

## Guarantees
- Read results are summaries with `as_of` dates; nothing the connector does changes records without approval in the app.
- Suspending a member, revoking a grant or disconnecting the app stops access on the next request.
- Document content is treated as untrusted data and is never executed as instructions.
