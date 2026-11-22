"""New Jersey resident gross income tax (NJ-1040). NJ income categories are computed independently from
federal AGI: each category is netted internally and floored at zero (N.J.S.A. 54A:5-1, 54A:5-2)."""
from __future__ import annotations

from decimal import Decimal
from typing import Any

from .calc import ZERO, Calc, D, bracket_tax, pos, whole
from .model import TaxInput
from .packs import Pack

DEFERRAL_CODES = ("D", "E", "F", "G", "S", "AA", "BB", "EE")


def compute(t: TaxInput, pack: Pack, fed: dict[str, Any]) -> dict[str, Any]:
    c = Calc("NJ")
    fs = t.filing_status
    inc = t.income
    if t.residency != "resident":
        c.block("nj_part_year", "Part-year and nonresident NJ returns (NJ-1040 part-year / NJ-1040NR) are not supported by this rule pack; "
                "provide a reconciled manual statement.")

    # Wages: NJ wages (box 16) include 401(k)/403(b) deferrals that are excluded federally.
    nj_wages = ZERO
    for w in inc.wages:
        if w.box16_nj is not None:
            nj_wages += D(w.box16_nj)
        else:
            est = D(w.box1) + sum((D(v) for k, v in w.box12.items() if k in ("D", "E", "F", "G", "S")), ZERO)
            nj_wages += est
            c.issue("nj_wages_estimated", f"NJ wages for {w.employer or 'an employer'} estimated as box 1 plus elective deferrals (box 16 missing).", "warning")
    c.set("nj.15", nj_wages, "N.J.S.A.54A:5-1(a)", note="W-2 box 16 (state wages)", facts=[f for w in inc.wages for f in w.facts], line=("NJ-1040", "15", "Wages"))

    interest = sum((D(i.amount) - D(i.us_obligations) + D(i.tax_exempt) - D(i.nj_exempt) for i in inc.interest), ZERO)
    interest += sum((D(k.interest) for k in inc.k1 if k.kind == "1041"), ZERO)
    c.set("nj.16a", pos(interest), "N.J.S.A.54A:5-1(h)", note="Taxable interest incl. non-NJ municipal; excludes U.S. obligations", line=("NJ-1040", "16a", "Taxable interest"))
    divs = sum((D(d.ordinary) + D(d.exempt_interest) - D(d.nj_exempt) for d in inc.dividends), ZERO)
    c.set("nj.17", pos(divs), "N.J.S.A.54A:5-1(g)", line=("NJ-1040", "17", "Dividends"))

    business = ZERO
    for b in inc.schedule_c:
        exp = sum((D(v) for v in b.expenses.values()), ZERO)
        sec179 = min(D(b.section_179), Decimal(25000))
        if D(b.section_179) > 25000:
            c.issue("nj_179", f"{b.name}: NJ limits Section 179 to $25,000; the excess is recovered through NJ depreciation (GIT-DEP).", "warning")
        business += D(b.gross) - D(b.returns) - D(b.cogs) - exp - D(b.depreciation) - sec179 - D(b.home_office)
    c.set("nj.18", pos(business), "N.J.S.A.54A:5-1(b)", note="Net profits from business; a net loss is not deductible against other categories",
          line=("NJ-1040", "18", "Net profits from business"))

    gains = sum((D(x.proceeds) - D(x.basis) + D(x.adjustment) for x in inc.capital), ZERO)
    gains += sum((D(d.cap_gain_dist) for d in inc.dividends), ZERO) + sum((D(k.st_gain) + D(k.lt_gain) + D(k.sec1231) for k in inc.k1 if k.kind == "1041"), ZERO)
    c.set("nj.19", pos(gains), "N.J.S.A.54A:5-1(c)", note="Net gains from disposition of property; no NJ capital loss carryover",
          line=("NJ-1040", "19", "Net gains or income from disposition of property"))

    pensions = ZERO
    for r in inc.retirement:
        if r.kind == "roth":
            continue
        if r.nj_taxable is not None:
            pensions += D(r.nj_taxable)
        else:
            pensions += D(r.taxable)
            if D(r.taxable) > 0:
                c.issue("nj_pension_basis", "NJ taxable portion of a retirement distribution was taken from federal taxable amount; "
                        "previously taxed NJ contributions may reduce it.", "warning")
    c.set("nj.20a", pensions, "N.J.S.A.54A:5-1(j)", line=("NJ-1040", "20a", "Taxable pensions, annuities and IRA withdrawals"))

    def k1_income(k) -> Decimal:
        return (D(k.ordinary) + D(k.rental) + D(k.interest) + D(k.dividends_ordinary) + D(k.st_gain) + D(k.lt_gain)
                + D(k.sec1231) + D(k.guaranteed_payments) - min(D(k.sec179), Decimal(25000)))

    partnership = sum((D(k.nj_source) if k.nj_source is not None else k1_income(k) for k in inc.k1 if k.kind == "1065"), ZERO)
    scorp = sum((D(k.nj_source) if k.nj_source is not None else k1_income(k) for k in inc.k1 if k.kind == "1120S"), ZERO)
    if any(k.kind == "1120S" for k in inc.k1):
        c.issue("nj_s_election", "NJ S corporation pro-rata share assumes the corporation has a valid NJ S election (CBT-2553).", "info")
    c.set("nj.21", pos(partnership), "N.J.S.A.54A:5-1(k)", line=("NJ-1040", "21", "Distributive share of partnership income"))
    c.set("nj.22", pos(scorp), "N.J.S.A.54A:5-1(p)", line=("NJ-1040", "22", "Net pro rata share of S corporation income"))

    rents = ZERO
    for e in fed.get("rentals", []):
        # NJ has no passive-loss rules; rental net uses the same allocation and 280A limits, floored by category.
        rents += D(e["schedule_e"]["net"])
    c.set("nj.23", pos(rents), "N.J.S.A.54A:5-1(d)", note="Net rents; a net loss does not offset other categories", line=("NJ-1040", "23", "Net gain from rents, royalties"))

    other = sum((D(o.nj_amount) if o.nj_amount is not None else D(o.amount) for o in inc.other), ZERO)
    c.set("nj.26", pos(other), "N.J.S.A.54A:5-1", note="Unemployment and Social Security are not NJ taxable", line=("NJ-1040", "26", "Other income"))
    total = c.set("nj.27", sum((c.get(k) for k in ("nj.15", "nj.16a", "nj.17", "nj.18", "nj.19", "nj.20a", "nj.21", "nj.22", "nj.23", "nj.26")), ZERO),
                  "sum", ["nj.15", "nj.16a", "nj.17", "nj.18", "nj.19", "nj.20a", "nj.21", "nj.22", "nj.23", "nj.26"], line=("NJ-1040", "27", "Total income"))

    pe = pack.rule("pension_exclusion")["value"]
    excl = ZERO
    eligible = any(p.age >= int(pe["age"]) and (fs == "mfj" or p.role == "primary") for p in t.people)
    if eligible and total <= D(pe["income_limit"]) and pensions > 0:
        pct = ZERO
        for upper, frac in pe["tiers"]:
            if total <= D(upper):
                pct = D(frac)
                break
        mx = D(pe["max"].get(fs, pe["max"]["single"]))
        excl = min(pensions, mx * pct) if pct < 1 else min(pensions, mx)
    c.set("nj.28c", excl, "N.J.S.A.54A:6-10", note="Pension exclusion (age 62+, income tiers)", line=("NJ-1040", "28c", "Total exclusion amount"))
    gross = c.set("nj.29", pos(total - excl), "sum", ["nj.27", "nj.28c"], line=("NJ-1040", "29", "New Jersey gross income"))

    threshold = D(pack.v("filing_threshold", fs))
    ex = pack.rule("exemptions")["value"]
    n_self = 2 if fs == "mfj" else 1
    seniors = sum(1 for p in t.people if (p.age >= 65 or p.blind) and (fs == "mfj" or p.role == "primary"))
    students = sum(1 for d in t.dependents if d.college_student and d.age < 22)
    exemptions = D(ex["taxpayer"]) * n_self + D(ex["age_65_or_blind"]) * seniors + D(ex["dependent"]) * len(t.dependents) \
        + D(ex["college_student"]) * students + D(ex["veteran"]) * t.nj.veterans
    c.set("nj.30", exemptions, "N.J.S.A.54A:3-1", line=("NJ-1040", "30", "Total exemption amount"))
    med_exp = D(t.nj.medical_expenses) if t.nj.medical_expenses is not None else D(t.itemized.medical)
    medical = pos(med_exp - gross * D(pack.v("medical_floor")))
    c.set("nj.31", medical, "N.J.S.A.54A:3-3", note="Medical expenses over 2% of NJ gross income", line=("NJ-1040", "31", "Medical expenses"))
    d529 = ZERO
    r529 = pack.rule("nj_529_deduction")["value"]
    if gross <= D(r529["income_limit"]):
        d529 = min(D(t.nj.nj_529_contributions), D(r529["max"]))
    c.set("nj.38b", d529, "P.L.2021,c.129", line=("NJ-1040", "38b", "NJBEST contributions"))
    deductions = c.set("nj.38", exemptions + medical + d529, "sum", ["nj.30", "nj.31", "nj.38b"], line=("NJ-1040", "38", "Total exemptions and deductions"))
    before_pt = c.set("nj.39", pos(gross - deductions), "sum", ["nj.29", "nj.38"], line=("NJ-1040", "39", "Taxable income before property tax deduction"))

    brackets = pack.v("brackets", fs)
    pt_rule = pack.rule("property_tax")["value"]
    pt_paid = D(t.nj.property_tax_paid) if t.nj.homeowner else D(t.nj.rent_paid) * D(pt_rule["renter_pct"])
    pt_ded = min(pt_paid, D(pt_rule["deduction_max"]))
    tax_with_ded = bracket_tax(pos(before_pt - pt_ded), brackets)
    tax_without = bracket_tax(before_pt, brackets)
    use_deduction = pt_paid > 0 and (tax_without - tax_with_ded) >= D(pt_rule["credit"])
    if gross <= threshold:
        use_deduction = False
    c.set("nj.41", pt_ded if use_deduction else ZERO, "Worksheet H", note="Property tax deduction when it saves more than the $50 credit",
          line=("NJ-1040", "41", "Property tax deduction"))
    taxable = c.set("nj.42", pos(before_pt - c.get("nj.41")), "sum", ["nj.39", "nj.41"], line=("NJ-1040", "42", "New Jersey taxable income"))
    tax = bracket_tax(taxable, brackets) if gross > threshold else ZERO
    note = "Tax Rate Schedule " + ("B" if fs in ("mfj", "hoh", "qss") else "A")
    if gross <= threshold:
        note = f"No tax: gross income {whole(gross)} at or below filing threshold {whole(threshold)}"
    elif taxable < 100000:
        note += "; NJ Tax Table midpoint rounding below $100,000 may differ by a few dollars"
    c.set("nj.43", tax, "N.J.S.A.54A:2-1", ["nj.42"], note=note, line=("NJ-1040", "43", "Tax"))
    cojc = min(D(t.nj.credit_other_jurisdictions), tax)
    c.set("nj.44", cojc, "Schedule NJ-COJ", line=("NJ-1040", "44", "Credit for income taxes paid to other jurisdictions"))
    after = c.set("nj.45", pos(tax - cojc), "sum", ["nj.43", "nj.44"], line=("NJ-1040", "45", "Balance of tax"))
    total_tax = c.set("nj.51", after, "sum", ["nj.45"], line=("NJ-1040", "51", "Total tax due"))

    wh = sum((D(w.nj_wh) for w in inc.wages), ZERO) + D(t.payments.nj_other_withholding)
    c.set("nj.52", wh, "W2.box17", facts=[f for w in inc.wages for f in w.facts], line=("NJ-1040", "52", "Total NJ income tax withheld"))
    pt_credit = D(pt_rule["credit"]) if (not use_deduction and pt_paid > 0 and gross > threshold) else ZERO
    c.set("nj.53", pt_credit, "N.J.S.A.54A:3A-20", line=("NJ-1040", "53", "Property tax credit"))
    est = D(t.payments.nj_estimated) + D(t.payments.nj_extension) + D(t.payments.prior_year_overpayment_nj)
    c.set("nj.54", est, "NJ-1040-ES", line=("NJ-1040", "54", "Estimated payments / credit from prior year"))
    ctc_rule = pack.rule("nj_child_credit")["value"]
    young = sum(1 for d in t.dependents if d.age <= int(ctc_rule["max_age"]))
    nj_ctc = ZERO
    if young:
        for upper, amt in ctc_rule["tiers"]:
            if taxable <= D(upper):
                nj_ctc = D(amt) * young
                break
    if fs == "mfs":
        nj_ctc = ZERO
    c.set("nj.65", nj_ctc, "N.J.S.A.54A:4-17.1", line=("NJ-1040", "65", "NJ Child Tax Credit"))
    employers = {w.employer for w in inc.wages}
    if len(employers) > 1:
        c.issue("nj_excess_ui", "Multiple employers: check for excess NJ UI/WF/SWF and FLI contributions (NJ-2450).", "info")
    payments = c.set("nj.66", wh + pt_credit + est + nj_ctc, "sum", ["nj.52", "nj.53", "nj.54", "nj.65"], line=("NJ-1040", "66", "Total withholdings, credits and payments"))
    diff = payments - total_tax
    c.set("nj.67", pos(-diff), "N.J.S.A.54A", line=("NJ-1040", "67", "Amount you owe"))
    c.set("nj.68", pos(diff), "N.J.S.A.54A", line=("NJ-1040", "68", "Overpayment"))

    et = pack.rule("estimated_tax")["value"]
    req = total_tax * D(et["safe_harbor_current"])
    if t.prior_year.nj_total_tax is not None:
        req = min(req, D(t.prior_year.nj_total_tax) * D(et["safe_harbor_prior"]))
    if pos(-diff) > D(et["min_balance"]) and wh + D(t.payments.nj_estimated) < req:
        c.issue("nj_underpayment", f"NJ payments below the safe harbor ({whole(req)}); NJ-2210 interest may apply.", "warning")

    summary = {
        "total_income": str(total), "gross_income": str(gross), "exemptions_deductions": str(deductions), "taxable_income": str(taxable),
        "tax": str(c.get("nj.43")), "total_tax": str(total_tax), "withholding": str(wh), "estimated_payments": str(est),
        "refundable_credits": str(pt_credit + nj_ctc), "total_payments": str(payments), "balance_due": str(c.get("nj.67")),
        "refund": str(c.get("nj.68")), "property_tax_treatment": "deduction" if use_deduction else ("credit" if pt_credit else "none"),
        "required_annual_payment": str(whole(req)),
    }
    return {"calc": c, "summary": summary}
