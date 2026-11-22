---
applyTo: "runners/legacy_runners/tax/**,app/Services/Tax/**,app/Jobs/Handlers/Tax*,app/Http/Controllers/Api/TaxController.php,docs/TAX_RULES.md"
---
# Tax engine rules

- Parameters (brackets, deductions, phase-outs, credits, limits) live only in versioned packs
  `runners/legacy_runners/tax/packs/{us,nj}-<year>.json`. Code must not hard-code year-specific amounts.
- Every pack value needs a source in `docs/TAX_RULES.md` (IRS Rev. Proc./form instructions, N.J.S.A./NJ-1040 instructions)
  and `legislation_status` (`enacted`, `provisional`, `hypothetical`). Editing a pack changes its hash; it returns to
  `draft` and must be re-approved by a named reviewer (`/tax/packs/{id}/approve`).
- Run status: `unsupported` if any unmodeled situation; `estimate` if missing inputs, assumptions, provisional law or
  unapproved packs; `calculated` only otherwise. `reconciled` / `cpa_reviewed` are set by explicit user actions.
- Every computed line emits a trace node (`calc.set(node, value, rule=..., inputs=..., facts=...)`) so `/trace?node=` works.
- Add or update hand-computed golden cases in `runners/tests/test_tax.py` with the arithmetic in comments. Never loosen
  tolerances to make a test pass.
- What-if overrides are hypothetical: they create `strategy` runs and must never mutate canonical facts.
- NJ differences from federal (no HSA deduction, 401(k) deferrals taxable, property-tax deduction vs credit, NJ CTC, etc.)
  are modeled explicitly in `nj.py`, not inferred from federal values.
