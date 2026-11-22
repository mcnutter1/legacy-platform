# Tax rule source register

Every value in `runners/legacy_runners/tax/packs/*.json` must trace to an authority below. A pack is **draft** until a
named professional approves its exact content hash in *Tax → Rule packs*; runs using any draft or provisional pack are
labeled **estimate**. Editing a pack changes its hash and requires re-approval.

| Pack | Status | Primary authority |
| --- | --- | --- |
| `us-2025` v2025.1 | enacted · draft until approved | Rev. Proc. 2024-40; Pub. L. 119-21 (OBBBA) amendments for 2025; 2025 Form 1040 & instructions |
| `us-2026` v2026.1 | enacted · draft | Rev. Proc. 2025-32; Pub. L. 119-21 provisions effective 2026. Verify against final 2026 forms |
| `nj-2025` v2025.1 | enacted · draft | N.J.S.A. 54A; 2025 NJ-1040 instructions (full-year residents) |
| `nj-2026` v2026.1 | **provisional** | Carries 2025 structure (NJ brackets are not indexed) until 2026 NJ-1040 instructions publish |

## Federal rule keys → authority

| Key | What it parameterizes | Authority |
| --- | --- | --- |
| `std_deduction`, `additional_std` | Standard / age-blind additional deduction | IRC §63(c); Rev. Proc. §3.15–3.16; OBBBA §70102 |
| `brackets` | Ordinary rate schedules by filing status | IRC §1(j); Rev. Proc. §3.01 |
| `cg_thresholds`, `unrecaptured_1250_rate`, `collectibles_rate` | Qualified dividend / LTCG 0-15-20% breakpoints; 25% and 28% rates | IRC §1(h); Rev. Proc. §3.03; Sch. D worksheet |
| `niit` | 3.8% NIIT thresholds (not indexed) | IRC §1411; Form 8960 |
| `addl_medicare`, `payroll` | 0.9% Additional Medicare; SS wage base; SE tax factors | IRC §3101(b)(2), §1401; SSA wage base announcement; Form 8959, Sch. SE |
| `amt` | Exemption, phase-out, 26/28% breakpoint | IRC §55; Rev. Proc. §3.11; OBBBA §70107; Form 6251 |
| `ctc` | Child tax credit, refundable portion, phase-out | IRC §24; OBBBA §70104; Sch. 8812 |
| `salt` | SALT cap and MAGI phase-down | IRC §164(b)(7) as amended by OBBBA §70120 |
| `senior_deduction` | $6,000 age-65 deduction 2025–2028 + phase-out | OBBBA §70103 |
| `tips_deduction`, `overtime_deduction` | Qualified tips / overtime deductions 2025–2028 | OBBBA §70201, §70202 |
| `car_loan_interest` | Qualified passenger vehicle loan interest | OBBBA §70203 |
| `qbi` | §199A thresholds and phase-in range | IRC §199A; Rev. Proc. §3.27; OBBBA §70105; Form 8995/8995-A |
| `itemized` | Mortgage limit, medical floor, charitable floors/caps (2026+) | IRC §163(h), §213, §170; OBBBA §70111, §70425 |
| `capital_loss_limit` | $3,000/$1,500 ordinary offset | IRC §1211(b) |
| `passive_allowance` | $25,000 rental allowance and MAGI phase-out | IRC §469(i) |
| `ss_taxability` | Base amounts for taxable Social Security | IRC §86; Pub. 915 worksheet |
| `estimated_tax` | Safe harbor 90%/100%/110% and AGI threshold | IRC §6654(d); Form 2210 |
| `contribution_limits` | 401(k)/403(b), catch-up incl. ages 60–63, IRA, HSA | IRC §402(g), §414(v), §219, §223; Notice 2024-80 / 2025 notice |
| `section_179` | Dollar limit and phase-out | IRC §179(b); OBBBA §70306 |
| `estate_gift` | Basic exclusion, annual exclusion | IRC §2010(c), §2503(b); OBBBA §70106 |

## New Jersey rule keys → authority

| Key | Authority |
| --- | --- |
| `brackets` | N.J.S.A. 54A:2-1; NJ-1040 Tax Rate Schedules A/B |
| `filing_threshold` | N.J.S.A. 54A:2-4; NJ-1040 instructions "Who must file" |
| `exemptions` | N.J.S.A. 54A:3-1 (personal, 65+, blind/disabled, dependents, college student dependents, veterans) |
| `medical_floor` | N.J.S.A. 54A:3-3 (2% of NJ gross income) |
| `property_tax` | N.J.S.A. 54A:3A-17 (deduction up to $15,000 or $50 credit; renters 18% of rent) |
| `pension_exclusion` | N.J.S.A. 54A:6-10 / 6-15 (income limits; full-year residents 62+) |
| `nj_child_credit` | N.J.S.A. 54A:4-17.1 (child under 6, income bands) |
| `nj_529_deduction` | N.J.S.A. 54A:3-10 (NJBEST contributions up to $10,000 with income limit) |
| `estimated_tax` | N.J.S.A. 54A:9-6; NJ-1040-ES (80% / 100% safe harbor) |
| `inheritance` | N.J.S.A. 54:34 (Class A exempt; Class C/D rates) — used by the estate model |

## Property tax records (inputs, not engine rules)

| Item | Source |
| --- | --- |
| Assessed land/improvements/total, property class, prior-year levy (`LAST_YR_TX`), deed book/page/date, sale price | NJ MOD-IV tax list via NJOGIS statewide parcels (public ArcGIS service); refreshed on lookup |
| Quarterly due dates Feb 1 / May 1 / Aug 1 / Nov 1, 10-day grace | N.J.S.A. 54:4-66, 54:4-67 |
| Projected installments = prior-year levy ÷ 4 (status `projected`, source `estimate`) | NJ preliminary bills are half the prior year (N.J.S.A. 54:4-66); final bills differ — replace with bill amounts |

Only **paid** installments become `property_expenses` (category `property_tax`), which is what the engine reads for
Schedule A real-estate tax, the NJ property-tax deduction/credit and Schedule E. Projections never reach a return.

## Explicitly unsupported (runs return `unsupported`, never a guess)

Part-year / nonresident NJ (NJ-1040NR), multi-state credit beyond NJ resident credit inputs, foreign earned income
exclusion, Form 8615 kiddie tax, farm income, ISO AMT credit carryforward beyond recorded amounts, NOL computation,
§1031 exchanges, installment sales, trust/estate fiduciary returns (1041), and any form not listed in the line map.

## Approval checklist (record in the approval note)

1. Diff pack JSON against the cited authority; check every amount for each filing status.
2. `cd runners && .venv/bin/python -m pytest tests/test_tax.py -q` passes; hand-computed fixtures reviewed.
3. Run the CPA parity fixtures for the year (Tax → unit → Calculate) and reconcile key lines to a prepared return.
4. Approve in the UI with your name/credential; the hash and note are audited.
