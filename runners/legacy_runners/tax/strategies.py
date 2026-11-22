"""Lawful tax what-if alternatives ranked against a no-action baseline. Each candidate re-runs the same
engine with an explicit input delta; nothing here fabricates deductions, use logs or dates."""
from __future__ import annotations

import copy
from decimal import Decimal
from typing import Any, Callable

from .calc import D, ZERO, pos, whole
from .engine import compute
from .model import TaxInput
from .packs import Pack


def _total(res: dict[str, Any]) -> Decimal:
    s = res.get("summary", {})
    return sum((D(v.get("total_tax")) for k, v in s.items() if k in ("federal", "nj")), ZERO)


def run(inp: dict[str, Any]) -> dict[str, Any]:
    base_input = TaxInput.model_validate(inp["tax_input"])
    goals = inp.get("goals", {})
    context = inp.get("context", {})
    base = compute(base_input)
    if base["status"] == "unsupported":
        return {"baseline": {"status": "unsupported"}, "candidates": [], "note": "Baseline is unsupported; strategies require a supported baseline."}
    base_tax = _total(base)
    pack = Pack("US", base_input.tax_year)
    limits = pack.rule("contribution_limits")["value"]
    year = base_input.tax_year
    candidates: list[dict[str, Any]] = []

    def candidate(kind: str, title: str, mutate: Callable[[dict], None], cash_out: Decimal, *, assumptions: list[str], evidence: list[str],
                  authorities: list[dict[str, str]], deadline: str | None, future: str, requires_professional: bool = True,
                  constraints: list[str] | None = None) -> None:
        data = copy.deepcopy(inp["tax_input"])
        mutate(data)
        try:
            res = compute(TaxInput.model_validate(data))
        except Exception as e:  # noqa: BLE001
            return
        if res["status"] == "unsupported":
            return
        tax = _total(res)
        saving = base_tax - tax
        candidates.append({
            "kind": kind, "title": title, "tax_year": year,
            "impact": {
                "federal_delta": str(D(res["summary"]["federal"]["total_tax"]) - D(base["summary"]["federal"]["total_tax"])),
                "nj_delta": str(D(res["summary"].get("nj", {}).get("total_tax", 0)) - D(base["summary"].get("nj", {}).get("total_tax", 0))),
                "current_year_tax_saving": str(whole(saving)),
                "cash_outlay": str(whole(cash_out)),
                "net_current_year_cash": str(whole(saving - cash_out)),
                "future_tax_note": future,
            },
            "assumptions": assumptions, "evidence_required": evidence, "authorities": authorities, "deadline": deadline,
            "eligibility_constraints": constraints or [], "requires_professional": requires_professional,
            "status": res["status"],
        })

    persons = [p for p in base_input.people]
    # 1. Elective deferral headroom per employee.
    for p in persons:
        wages = [w for w in base_input.income.wages if w.person == p.id]
        if not wages:
            continue
        deferred = sum((D(v) for w in wages for k, v in w.box12.items() if k in ("D", "E", "G", "AA", "EE")), ZERO)
        cap = D(limits["401k"]) + (D(limits["401k_catchup_60_63"]) if 60 <= p.age <= 63 else (D(limits["401k_catchup"]) if p.age >= 50 else ZERO))
        room = pos(cap - deferred)
        if room > 0:
            def mut(d, pid=p.id, room=room):
                for w in d["income"]["wages"]:
                    if w["person"] == pid:
                        w["box1"] = str(D(w.get("box1", 0)) - room)
                        w.setdefault("box12", {})["D"] = str(D(w.get("box12", {}).get("D", 0)) + room)
                        break
            candidate("retirement_deferral", f"Increase pre-tax 401(k) deferrals by {whole(room)}", mut, ZERO,
                      assumptions=["Employer plan permits additional deferrals through remaining pay periods", "Cash flow can absorb reduced net pay"],
                      evidence=["Plan document / payroll election confirmation"],
                      authorities=[{"cite": "IRC 402(g)", "url": "https://www.irs.gov/retirement-plans/plan-participant-employee/retirement-topics-401k-and-profit-sharing-plan-contribution-limits"}],
                      deadline=f"{year}-12-31", future="Deferred amounts are taxed on withdrawal; NJ taxes deferrals now (no NJ saving).",
                      requires_professional=False, constraints=["Deferrals must be made through payroll before year end"])

    # 2. HSA headroom.
    hsa_cap = D(limits["hsa_family"] if base_input.filing_status == "mfj" else limits["hsa_self"])
    hsa_room = pos(hsa_cap - D(base_input.adjustments.hsa))
    if hsa_room > 0 and context.get("hdhp_covered"):
        candidate("hsa", f"Contribute {whole(hsa_room)} more to an HSA", lambda d: d["adjustments"].__setitem__("hsa", str(D(d["adjustments"].get("hsa", 0)) + hsa_room)),
                  hsa_room, assumptions=["Covered by a qualifying high-deductible health plan for the full year"],
                  evidence=["HDHP coverage confirmation", "Form 5498-SA"],
                  authorities=[{"cite": "IRC 223", "url": "https://www.irs.gov/publications/p969"}],
                  deadline=f"{year + 1}-04-15", future="NJ does not allow an HSA deduction; federal benefit only.", requires_professional=False,
                  constraints=["No other disqualifying coverage", "Not enrolled in Medicare"])

    # 3. Roth conversion filling the current bracket.
    fed_ti = D(base["summary"]["federal"]["taxable_income"])
    brackets = pack.v("brackets", "mfj" if base_input.filing_status == "qss" else base_input.filing_status)
    top = None
    for upper, _rate in brackets:
        if upper is not None and D(upper) > fed_ti:
            top = D(upper)
            break
    pretax_available = D(context.get("pretax_ira_balance", 0))
    if top is not None and pretax_available > 0:
        conv = min(top - fed_ti, pretax_available)
        if conv >= 1000:
            def mut_conv(d, conv=conv):
                d["income"].setdefault("retirement", []).append({"person": persons[0].id, "gross": str(conv), "taxable": str(conv), "kind": "ira"})
            candidate("roth_conversion", f"Convert {whole(conv)} of pre-tax IRA to Roth (fills current bracket)", mut_conv, ZERO,
                      assumptions=["Tax on conversion paid from taxable cash, not the IRA", "Pro-rata rule computed on all IRAs (Form 8606)"],
                      evidence=["IRA year-end balances", "Form 8606 basis"],
                      authorities=[{"cite": "IRC 408A(d)(3)", "url": "https://www.irs.gov/retirement-plans/roth-iras"}],
                      deadline=f"{year}-12-31", future="Increases current tax; may reduce future RMDs and tax on qualified withdrawals. Compare in Planning.",
                      constraints=["Conversion is irrevocable", "May affect IRMAA two years later and ACA credits"])

    # 4. Charitable bunching (two years in one) for itemizers near the standard deduction.
    cash_gifts = D(base_input.itemized.charitable_cash)
    if cash_gifts > 0:
        def mut_bunch(d, g=cash_gifts):
            d["itemized"]["charitable_cash"] = str(g * 2)
        candidate("charitable_bunching", f"Bunch two years of giving ({whole(cash_gifts * 2)}) via a donor-advised fund", mut_bunch, cash_gifts,
                  assumptions=["Next year's planned gifts are pre-funded this year", "Donor-advised fund sponsor is a qualifying public charity"],
                  evidence=["Contemporaneous written acknowledgment from the sponsor"],
                  authorities=[{"cite": "IRC 170", "url": "https://www.irs.gov/charities-non-profits/charitable-organizations/charitable-contribution-deductions"}],
                  deadline=f"{year}-12-31", future="Next year likely uses the standard deduction; evaluate the two-year total.", requires_professional=False)

    # 5. Tax-loss harvesting from supplied unrealized losses (wash-sale coverage across spouses' accounts required).
    harvest = D(context.get("harvestable_losses", 0))
    if harvest > 0:
        def mut_h(d, h=harvest):
            d["income"].setdefault("capital", []).append({"term": "long", "proceeds": "0", "basis": str(h), "description": "Harvested loss (hypothetical)"})
        candidate("loss_harvest", f"Realize {whole(harvest)} of unrealized losses", mut_h, ZERO,
                  assumptions=["No substantially identical purchase within 30 days before/after in any household account, IRA or spouse account"],
                  evidence=["Lot-level unrealized loss report", "Wash-sale check across all household accounts"],
                  authorities=[{"cite": "IRC 1091", "url": "https://www.irs.gov/publications/p550"}],
                  deadline=f"{year}-12-31", future="Lowers basis of replacement positions; future gains increase.", requires_professional=False)

    # 6. Business equipment placed in service this year (Section 179/bonus) when a Schedule C exists.
    purchase = D(context.get("planned_business_purchase", 0))
    if purchase > 0 and base_input.income.schedule_c:
        def mut_179(d, amt=purchase):
            d["income"]["schedule_c"][0]["section_179"] = str(D(d["income"]["schedule_c"][0].get("section_179", 0)) + amt)
        candidate("section_179", f"Place {whole(purchase)} of qualifying equipment in service before year end", mut_179, purchase,
                  assumptions=["Property is purchased AND placed in service in the business this year", "Business use exceeds 50% where listed property"],
                  evidence=["Invoice and delivery/installation date", "Business-use log for listed property"],
                  authorities=[{"cite": "IRC 179; 168(k)", "url": "https://www.irs.gov/instructions/i4562"}],
                  deadline=f"{year}-12-31", future="Recapture on early disposition or business use below 50%; NJ limits 179 to $25,000.",
                  constraints=["179 deduction limited to business taxable income", "Purchase only if needed for the business — compare after-tax cash"])

    # 7. Estimated payments to reach safe harbor.
    req = D(base["summary"]["federal"]["required_annual_payment"])
    paid = D(base["summary"]["federal"]["withholding"]) + D(base["summary"]["federal"]["estimated_payments"])
    if req > paid:
        gap = req - paid
        candidates.append({
            "kind": "safe_harbor", "title": f"Pay {whole(gap)} in federal estimates or extra withholding to meet the safe harbor", "tax_year": year,
            "impact": {"federal_delta": "0", "nj_delta": "0", "current_year_tax_saving": str(whole(D(base["summary"]["federal"]["penalty_estimate"]))),
                       "cash_outlay": str(whole(gap)), "net_current_year_cash": "0", "future_tax_note": "Timing only; avoids underpayment penalty."},
            "assumptions": ["Extra withholding is treated as paid evenly through the year (IRC 6654(g))"],
            "evidence_required": ["Payment confirmations"], "authorities": [{"cite": "IRC 6654", "url": "https://www.irs.gov/forms-pubs/about-form-2210"}],
            "deadline": None, "eligibility_constraints": [], "requires_professional": False, "status": base["status"],
        })

    goal = goals.get("rank_by", "net_current_year_cash")
    candidates.sort(key=lambda c: D(c["impact"].get(goal, c["impact"]["net_current_year_cash"])), reverse=True)
    return {
        "baseline": {"status": base["status"], "total_tax": str(base_tax), "summary": base["summary"]},
        "candidates": candidates,
        "disclaimer": "Projections are conditional on the stated assumptions and require professional review before acting.",
    }
