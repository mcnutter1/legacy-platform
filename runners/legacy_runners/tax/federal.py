"""Federal individual income tax (Form 1040 and supported schedules) as an ordered calculation graph."""
from __future__ import annotations

from decimal import ROUND_CEILING, Decimal
from typing import Any

from .calc import ZERO, Calc, D, bracket_tax, marginal_rate, pos, whole
from .model import TaxInput
from .packs import Pack
from .rental import classify, schedule_e

MARRIED = {"mfj", "qss", "mfs"}


def _status_key(fs: str) -> str:
    return "mfj" if fs == "qss" else fs


def _facts(items) -> list[str]:
    out: list[str] = []
    for i in items:
        out.extend(getattr(i, "facts", []) or [])
    return out


def compute(t: TaxInput, pack: Pack) -> dict[str, Any]:
    c = Calc("US")
    fs = t.filing_status
    sk = _status_key(fs)
    inc = t.income
    if fs == "qss" and not t.dependents:
        c.issue("qss_dependent", "Qualifying surviving spouse status requires a dependent child.", "error")

    # ---------------- Income ----------------
    wages = sum((D(w.box1) for w in inc.wages), ZERO)
    c.set("f1040.1a", wages, "W2.box1", note="Sum of W-2 box 1", facts=_facts(inc.wages), line=("1040", "1a", "Wages (W-2 box 1)"))
    c.set("f1040.1z", wages, "sum", ["f1040.1a"], line=("1040", "1z", "Total wages"))
    tax_exempt_int = sum((D(i.tax_exempt) for i in inc.interest), ZERO) + sum((D(d.exempt_interest) for d in inc.dividends), ZERO)
    c.set("f1040.2a", tax_exempt_int, "1099-INT.box8", line=("1040", "2a", "Tax-exempt interest"))
    k1_int = sum((D(k.interest) for k in inc.k1), ZERO)
    c.set("f1040.2b", sum((D(i.amount) for i in inc.interest), ZERO) + k1_int, "1099-INT.box1+K1", facts=_facts(inc.interest), line=("1040", "2b", "Taxable interest"))
    qd = sum((D(d.qualified) for d in inc.dividends), ZERO) + sum((D(k.dividends_qualified) for k in inc.k1), ZERO)
    od = sum((D(d.ordinary) for d in inc.dividends), ZERO) + sum((D(k.dividends_ordinary) for k in inc.k1), ZERO)
    if qd > od:
        c.issue("qualified_exceeds_ordinary", "Qualified dividends exceed ordinary dividends; check 1099-DIV boxes 1a/1b.", "error")
    c.set("f1040.3a", qd, "1099-DIV.box1b", facts=_facts(inc.dividends), line=("1040", "3a", "Qualified dividends"))
    c.set("f1040.3b", od, "1099-DIV.box1a", facts=_facts(inc.dividends), line=("1040", "3b", "Ordinary dividends"))

    # Retirement & Social Security
    ira = [r for r in inc.retirement if r.kind in ("ira", "roth")]
    pens = [r for r in inc.retirement if r.kind not in ("ira", "roth")]
    c.set("f1040.4a", sum((D(r.gross) for r in ira), ZERO), "1099-R.box1", line=("1040", "4a", "IRA distributions"))
    c.set("f1040.4b", sum((D(r.taxable) for r in ira), ZERO), "1099-R.box2a", facts=_facts(ira), line=("1040", "4b", "IRA taxable amount"))
    c.set("f1040.5a", sum((D(r.gross) for r in pens), ZERO), "1099-R.box1", line=("1040", "5a", "Pensions and annuities"))
    c.set("f1040.5b", sum((D(r.taxable) for r in pens), ZERO), "1099-R.box2a", facts=_facts(pens), line=("1040", "5b", "Pensions taxable amount"))

    # ---------------- Schedule D ----------------
    st = sum((D(x.proceeds) - D(x.basis) + D(x.adjustment) for x in inc.capital if x.term == "short"), ZERO) + sum((D(k.st_gain) for k in inc.k1), ZERO)
    sec1231 = sum((D(k.sec1231) for k in inc.k1), ZERO)
    lt = (sum((D(x.proceeds) - D(x.basis) + D(x.adjustment) for x in inc.capital if x.term == "long"), ZERO)
          + sum((D(d.cap_gain_dist) for d in inc.dividends), ZERO) + sum((D(k.lt_gain) for k in inc.k1), ZERO) + pos(sec1231))
    st_c = st - D(t.carryforwards.capital_loss_st)
    lt_c = lt - D(t.carryforwards.capital_loss_lt)
    c.set("schd.7", st_c, "ScheduleD.7", note="Net short-term incl. carryover", facts=_facts(inc.capital), line=("Schedule D", "7", "Net short-term capital gain/(loss)"))
    c.set("schd.15", lt_c, "ScheduleD.15", note="Net long-term incl. distributions, 1231 gains and carryover", line=("Schedule D", "15", "Net long-term capital gain/(loss)"))
    net_cap = st_c + lt_c
    c.set("schd.16", net_cap, "ScheduleD.16", ["schd.7", "schd.15"], line=("Schedule D", "16", "Net capital gain/(loss)"))
    limit = pack.d("capital_loss_limit", sk)
    cap_line7 = net_cap if net_cap >= 0 else -min(-net_cap, limit)
    c.set("f1040.7", cap_line7, "IRC1211(b)", ["schd.16"], line=("1040", "7", "Capital gain or (loss)"))
    carry_st = carry_lt = ZERO
    if net_cap < 0:
        # Capital Loss Carryover Worksheet: allowed loss absorbs short-term first.
        allowed = min(-net_cap, limit)
        if st_c < 0:
            st_use = min(-st_c, allowed + pos(lt_c))
            carry_st = pos(-st_c - st_use)
            allowed_left = pos(allowed + pos(lt_c) - st_use)
        else:
            allowed_left = allowed + pos(st_c)
        if lt_c < 0:
            carry_lt = pos(-lt_c - allowed_left)
    c.set("carry.capital_loss_st", carry_st, "CapitalLossCarryoverWorksheet")
    c.set("carry.capital_loss_lt", carry_lt, "CapitalLossCarryoverWorksheet")
    ncg = pos(min(lt_c, net_cap))
    g28 = min(sum((D(x.proceeds) - D(x.basis) + D(x.adjustment) for x in inc.capital if x.term == "long" and x.collectible), ZERO), ncg)
    g28 = pos(g28)
    g25 = pos(min(sum((D(x.unrecaptured_1250) for x in inc.capital), ZERO) + sum((D(d.unrecaptured_1250) for d in inc.dividends), ZERO), ncg - g28))

    # ---------------- Schedule C / SE ----------------
    sched_c_total = ZERO
    se_by_person: dict[str, Decimal] = {}
    qbi_items: list[dict[str, Any]] = []
    for b in inc.schedule_c:
        exp = sum((D(v) for v in b.expenses.values()), ZERO)
        net = D(b.gross) - D(b.returns) - D(b.cogs) - exp - D(b.depreciation) - D(b.section_179) - D(b.home_office)
        sched_c_total += net
        se_by_person[b.person] = se_by_person.get(b.person, ZERO) + net
        if b.qbi_eligible:
            qbi_items.append({"name": b.name, "qbi": net, "w2": D(b.w2_wages), "ubia": D(b.ubia), "sstb": b.sstb, "person": b.person, "se": True})
        if D(b.section_179) > pack.d("section_179", None, "limit"):
            c.issue("sec179_limit", f"Section 179 for {b.name} exceeds the annual limit.", "error")
    c.set("sch1.3", sched_c_total, "ScheduleC.31", facts=_facts(inc.schedule_c), line=("Schedule 1", "3", "Business income or (loss)"))
    for k in inc.k1:
        if k.person:
            se_by_person[k.person] = se_by_person.get(k.person, ZERO) + D(k.se_earnings) + D(k.guaranteed_payments) * (0 if D(k.se_earnings) else 1)

    ss_base = pack.d("payroll", None, "ss_wage_base")
    se_factor = pack.d("payroll", None, "se_factor")
    se_tax_total = ZERO
    for person, earnings in se_by_person.items():
        net_se = earnings * se_factor
        if net_se < pack.d("payroll", None, "se_min"):
            continue
        w2_ss = sum((D(w.box3) for w in inc.wages if w.person == person), ZERO)
        ss_part = min(net_se, pos(ss_base - w2_ss)) * pack.d("payroll", None, "se_ss_rate")
        med_part = net_se * pack.d("payroll", None, "se_medicare_rate")
        se_tax_total += ss_part + med_part
    se_tax = c.set("schse.12", se_tax_total, "ScheduleSE", note="SE tax across individuals", line=("Schedule SE", "12", "Self-employment tax"))
    half_se = c.set("sch1.15", se_tax / 2, "IRC164(f)", ["schse.12"], line=("Schedule 1", "15", "Deductible part of SE tax"))

    # ---------------- K-1 basis/at-risk ----------------
    k1_ordinary_total = ZERO
    k1_passive_income = ZERO
    k1_passive_loss = ZERO
    for k in inc.k1:
        amt = D(k.ordinary) + D(k.guaranteed_payments) - D(k.sec179)
        if amt < 0:
            if k.basis_available is None or k.at_risk is None:
                c.block("k1_basis_required", f"K-1 loss from {k.entity} requires partner/shareholder basis and at-risk amounts.", entity=k.entity)
            else:
                allowed = min(-amt, D(k.basis_available), D(k.at_risk))
                if allowed < -amt:
                    c.issue("k1_loss_limited", f"K-1 loss from {k.entity} limited by basis/at-risk; {whole(-amt - allowed)} suspended.", entity=k.entity)
                amt = -allowed
        if k.passive:
            if amt >= 0:
                k1_passive_income += amt
            else:
                k1_passive_loss += amt
        else:
            k1_ordinary_total += amt
        if D(k.qbi) != 0:
            qbi_items.append({"name": k.entity, "qbi": D(k.qbi), "w2": D(k.w2_wages), "ubia": D(k.ubia), "sstb": k.sstb, "person": k.person, "se": False})
    k1_rental = sum((D(k.rental) for k in inc.k1), ZERO)

    # ---------------- Rentals & passive limitation ----------------
    rentals_out = []
    nonpassive_rental = ZERO
    allowance_pool: list[tuple[str, Decimal]] = []
    passive_other: list[tuple[str, Decimal]] = []
    passive_income = k1_passive_income + pos(k1_rental)
    if k1_rental < 0:
        passive_other.append(("k1_rental", k1_rental))
    sched_a_extra_interest = ZERO
    sched_a_extra_taxes = ZERO
    for r in inc.rentals:
        cls = classify(r)
        se = schedule_e(r, cls)
        net = D(se["net"]) - D(r.prior_suspended_loss) - D(t.carryforwards.passive.get(r.property_id, 0))
        # Personal-use interest is qualified residence interest only when the unit is used as a residence.
        sched_a_extra_interest += D(se.get("schedule_a_interest", 0)) if cls["used_as_residence"] else ZERO
        sched_a_extra_taxes += D(se.get("schedule_a_taxes", 0)) if cls["category"] in ("residence_rental", "minimal_rental", "rental") else ZERO
        entry = {"property_id": r.property_id, "name": r.name, "classification": cls, "schedule_e": se, "net_before_passive": str(whole(net))}
        if cls["category"] in ("minimal_rental", "no_use"):
            entry["allowed"] = "0"
        elif not cls["passive"]:
            nonpassive_rental += net
            entry["allowed"] = str(whole(net))
        elif net >= 0:
            passive_income += net
            entry["allowed"] = str(whole(net))
        elif cls["loss_rule"] == "special_allowance" and cls["category"] == "rental":
            allowance_pool.append((r.property_id, net))
        else:
            passive_other.append((r.property_id, net))
        if cls["activity"] != "rental_activity" and r.significant_services:
            c.issue("schedule_c_rental", f"{r.name}: significant services may require Schedule C and SE tax instead of Schedule E.", "warning")
        rentals_out.append(entry)

    # MAGI for the special allowance excludes passive losses (Form 8582 worksheet, simplified).
    pre_agi_other = (c.get("f1040.1z") + c.get("f1040.2b") + c.get("f1040.3b") + c.get("f1040.4b") + c.get("f1040.5b")
                     + c.get("f1040.7") + sched_c_total + k1_ordinary_total + nonpassive_rental + passive_income)
    pa = pack.rule("passive_allowance")["value"]
    magi_8582 = pos(pre_agi_other - half_se)
    max_allow = D(pa["mfs_apart"]) if fs == "mfs" and t.mfs_lived_apart else (ZERO if fs == "mfs" else D(pa["amount"]))
    allowance = pos(max_allow - pos(magi_8582 - D(pa["phaseout_start"])) * D(pa["rate"]))
    c.set("f8582.allowance", allowance, "IRC469(i)", note=f"Special allowance after MAGI phase-out (MAGI {whole(magi_8582)})")

    if k1_passive_loss < 0:
        passive_other.append(("k1_passive", k1_passive_loss))
    ap_loss = -sum((v for _, v in allowance_pool), ZERO)
    other_loss = -sum((v for _, v in passive_other), ZERO)
    total_loss = ap_loss + other_loss
    passive_losses_total = -total_loss
    suspended = ZERO
    if total_loss > passive_income:
        # Passive income absorbs losses pro rata; the special allowance then applies to remaining
        # active-participation rental real estate losses (Form 8582, simplified).
        absorbed_ap = ap_loss * passive_income / total_loss if total_loss else ZERO
        allowance_used = min(allowance, pos(ap_loss - absorbed_ap))
        suspended = pos(total_loss - passive_income - allowance_used)
        c.issue("form_8582", "Passive losses exceed passive income; Form 8582 allocation is pro rata (worksheets 5-7 simplified).", "info")
    passive_net = passive_income + passive_losses_total + suspended
    c.set("f8582.allowed_net", passive_net, "Form8582", note="Passive income less allowed passive losses")
    c.set("f8582.suspended", suspended, "Form8582", note="Suspended passive losses carried forward")
    if suspended > 0:
        for pid, loss in allowance_pool + passive_other:
            share = (loss / passive_losses_total) * suspended if passive_losses_total else ZERO
            for e in rentals_out:
                if e["property_id"] == pid:
                    e["suspended"] = str(whole(share))
                    e["allowed"] = str(whole(loss + share))
    for e in rentals_out:
        e.setdefault("allowed", e.get("net_before_passive"))
        e.setdefault("suspended", "0")

    sched_e_total = nonpassive_rental + passive_net + k1_ordinary_total
    c.set("sch1.5", sched_e_total, "ScheduleE.41", facts=_facts(inc.rentals) + _facts(inc.k1), line=("Schedule 1", "5", "Rental real estate, royalties, partnerships, S corps"))

    other = sum((D(o.amount) for o in inc.other), ZERO) + (sec1231 if sec1231 < 0 else ZERO)
    c.set("sch1.7", D(inc.unemployment), "1099-G", line=("Schedule 1", "7", "Unemployment compensation"))
    c.set("sch1.8z", other, "Schedule1.8", facts=_facts(inc.other), line=("Schedule 1", "8z", "Other income"))
    c.set("sch1.10", c.get("sch1.3") + c.get("sch1.5") + c.get("sch1.7") + c.get("sch1.8z"), "sum", ["sch1.3", "sch1.5", "sch1.7", "sch1.8z"],
          line=("Schedule 1", "10", "Additional income"))
    c.set("f1040.8", c.get("sch1.10"), "Schedule1.10", ["sch1.10"], line=("1040", "8", "Additional income from Schedule 1"))

    # ---------------- Adjustments ----------------
    a = t.adjustments
    adj_pre = half_se + D(a.se_health_insurance) + D(a.se_retirement) + D(a.hsa) + D(a.ira_deductible) + min(D(a.educator), Decimal(300) * (2 if fs == "mfj" else 1)) + D(a.other)
    income_before_ss = (c.get("f1040.1z") + c.get("f1040.2b") + c.get("f1040.3b") + c.get("f1040.4b") + c.get("f1040.5b") + c.get("f1040.7") + c.get("f1040.8"))
    # Social Security taxability (Pub. 915 worksheet).
    ss_gross = sum((D(s.benefits) for s in inc.social_security), ZERO)
    c.set("f1040.6a", ss_gross, "SSA-1099.box5", facts=_facts(inc.social_security), line=("1040", "6a", "Social security benefits"))
    taxable_ss = ZERO
    if ss_gross > 0:
        base1 = pack.d("ss_taxability", sk, "base1")
        base2 = pack.d("ss_taxability", sk, "base2")
        if fs == "mfs" and t.mfs_lived_apart:
            base1, base2 = Decimal(25000), Decimal(34000)
        prov = income_before_ss - adj_pre + c.get("f1040.2a") + ss_gross / 2
        l9 = prov - base1
        if l9 > 0:
            l10 = base2 - base1
            l11 = pos(l9 - l10)
            l14 = min(ss_gross / 2, min(l9, l10) / 2)
            taxable_ss = min(l14 + l11 * Decimal("0.85"), ss_gross * Decimal("0.85"))
    c.set("f1040.6b", pos(taxable_ss), "IRC86", ["f1040.6a"], line=("1040", "6b", "Taxable social security"))

    total_income = c.set("f1040.9", income_before_ss + c.get("f1040.6b"), "sum",
                         ["f1040.1z", "f1040.2b", "f1040.3b", "f1040.4b", "f1040.5b", "f1040.6b", "f1040.7", "f1040.8"],
                         line=("1040", "9", "Total income"))
    sli = D(a.student_loan_interest)
    if sli:
        sli = min(sli, Decimal(2500))
        lo, hi = (Decimal(170000), Decimal(200000)) if fs == "mfj" else (Decimal(85000), Decimal(100000))
        magi = total_income - adj_pre
        if fs == "mfs":
            sli = ZERO
        elif magi > lo:
            sli = pos(sli - sli * min(Decimal(1), (magi - lo) / (hi - lo)))
        c.issue("student_loan_phaseout", "Student loan interest phase-out uses 2025 statutory ranges; verify for the tax year.", "info")
    adjustments = c.set("sch1.26", adj_pre + sli, "Schedule1.26", ["sch1.15"], line=("Schedule 1", "26", "Adjustments to income"))
    c.set("f1040.10", adjustments, "Schedule1.26", ["sch1.26"], line=("1040", "10", "Adjustments to income"))
    agi = c.set("f1040.11", total_income - adjustments, "IRC62", ["f1040.9", "f1040.10"], line=("1040", "11", "Adjusted gross income"))

    # ---------------- Deductions ----------------
    std = pack.d("std_deduction", fs)
    add_amt = pack.d("additional_std", None, "married" if fs in MARRIED else "unmarried")
    adds = 0
    for p in t.people:
        if fs != "mfj" and p.role == "spouse":
            continue
        adds += (1 if p.age >= 65 else 0) + (1 if p.blind else 0)
    std_total = std + add_amt * adds
    it = t.itemized
    medical = pos(D(it.medical) - agi * pack.d("itemized", None, "medical_floor"))
    salt_paid = D(it.state_local_income_tax) + D(it.real_estate_tax) + D(it.personal_property_tax) + sched_a_extra_taxes
    salt_cap = pack.d("salt", sk, "cap")
    salt_floor = pack.d("salt", sk, "floor")
    phase_start = pack.d("salt", sk, "phaseout_start")
    salt_cap_eff = max(salt_floor, salt_cap - pos(agi - phase_start) * pack.d("salt", None, "phaseout_rate"))
    salt = min(salt_paid, salt_cap_eff)
    c.set("scha.5e", salt, "IRC164(b)(6)", note=f"SALT paid {whole(salt_paid)} limited to {whole(salt_cap_eff)}", line=("Schedule A", "5e", "State and local taxes (limited)"))
    mort = D(it.mortgage_interest) + D(it.mortgage_points) + sched_a_extra_interest
    limit_debt = pack.d("itemized", None, "mortgage_debt_limit")
    if D(it.mortgage_principal_outstanding) > limit_debt:
        mort = mort * limit_debt / D(it.mortgage_principal_outstanding)
        c.issue("mortgage_limit", "Mortgage interest prorated for acquisition debt above the limit (Pub. 936 method simplified).", "warning")
    nii_for_invest = c.get("f1040.2b") + (od - qd)
    inv_int = min(D(it.investment_interest), nii_for_invest)
    if D(it.investment_interest) > inv_int:
        c.issue("investment_interest_carryforward", f"Investment interest of {whole(D(it.investment_interest) - inv_int)} exceeds net investment income and carries forward (Form 4952).", "info")
    floor = agi * D(pack.v("itemized", "charity_floor") or 0)
    charity = min(D(it.charitable_cash), agi * pack.d("itemized", None, "charity_cash_limit")) + min(D(it.charitable_noncash), agi * Decimal("0.30")) + D(t.carryforwards.charitable)
    charity = pos(charity - floor)
    itemized = medical + salt + mort + inv_int + charity + D(it.other)
    c.set("scha.17", itemized, "ScheduleA.17", line=("Schedule A", "17", "Total itemized deductions"))

    use_itemized = it.force_itemize or itemized > std_total
    nonitem_charity = ZERO
    nic = pack.v("itemized", "nonitemizer_charity")
    if not use_itemized and nic and nic != "0":
        cap = D(nic["mfj"] if isinstance(nic, dict) and fs == "mfj" else (nic["default"] if isinstance(nic, dict) else nic))
        nonitem_charity = min(D(it.charitable_cash), cap)
    deduction = itemized if use_itemized else std_total
    # OBBBA 2026+: itemized deductions limited to a 35% benefit for 37% bracket taxpayers (IRC 68).
    lim_rate = pack.v("itemized", "limitation_rate")
    brackets = pack.v("brackets", sk)
    if use_itemized and lim_rate:
        top_threshold = D(brackets[-2][0])
        excess = pos(agi - itemized - top_threshold)
        if excess > 0:
            reduction = Decimal(2) / Decimal(37) * min(itemized, excess)
            deduction -= reduction
            c.issue("itemized_limitation", f"Itemized deductions reduced by {whole(reduction)} under IRC 68 (2/37 rule).", "info")
    c.set("f1040.12", deduction + nonitem_charity, "IRC63", note="Itemized" if use_itemized else "Standard", line=("1040", "12", "Standard or itemized deduction"))

    # ---------------- Schedule 1-A (2025-2028) ----------------
    one_a = ZERO
    if t.tax_year in (2025, 2026, 2027, 2028):
        magi = agi
        tips = sum((D(w.tips) for w in inc.wages), ZERO)
        if tips:
            mx = pack.d("tips_deduction", sk, "max")
            red = (pos(magi - pack.d("tips_deduction", sk, "phaseout_start")) / 1000).to_integral_value(ROUND_CEILING) * pack.d("tips_deduction", None, "per_1000")
            one_a += pos(min(tips, mx) - red) if fs != "mfs" else ZERO
        ot = sum((D(w.overtime_premium) for w in inc.wages), ZERO)
        if ot:
            mx = pack.d("overtime_deduction", sk, "max")
            red = (pos(magi - pack.d("overtime_deduction", sk, "phaseout_start")) / 1000).to_integral_value(ROUND_CEILING) * pack.d("overtime_deduction", None, "per_1000")
            one_a += pos(min(ot, mx) - red) if fs != "mfs" else ZERO
        if D(a.car_loan_interest):
            mx = pack.d("car_loan_interest", None, "max")
            red = (pos(magi - pack.d("car_loan_interest", sk, "phaseout_start")) / 1000).to_integral_value(ROUND_CEILING) * pack.d("car_loan_interest", None, "per_1000")
            one_a += pos(min(D(a.car_loan_interest), mx) - red)
        sd = pack.rule("senior_deduction")["value"]
        seniors = sum(1 for p in t.people if p.age >= int(sd["age"]) and (fs == "mfj" or p.role == "primary"))
        if seniors and fs != "mfs":
            base = D(sd["amount"]) * seniors
            red = pos(magi - pack.d("senior_deduction", sk, "phaseout_start")) * D(sd["phaseout_rate"])
            one_a += pos(base - red)
    c.set("sch1a.38", one_a, "Schedule1-A", line=("Schedule 1-A", "38", "Additional deductions (tips, overtime, car loan interest, seniors)"))

    # ---------------- QBI ----------------
    ti_before_qbi = pos(agi - c.get("f1040.12") - one_a)
    qbi_ded = ZERO
    if qbi_items or any(D(d.section_199a) for d in inc.dividends):
        rate = pack.d("qbi", None, "rate")
        threshold = pack.d("qbi", sk, "threshold")
        phase = pack.d("qbi", sk, "phase_in")
        excess_ratio = min(Decimal(1), pos(ti_before_qbi - threshold) / phase) if phase else Decimal(1)
        comp = ZERO
        total_se_net = sum((i["qbi"] for i in qbi_items if i["se"]), ZERO)
        for i in qbi_items:
            q = i["qbi"]
            if i["se"] and total_se_net > 0 and q > 0:
                q -= (half_se + D(a.se_health_insurance) + D(a.se_retirement)) * q / total_se_net
            if i["sstb"]:
                applicable = Decimal(1) - excess_ratio
                q *= applicable
                w2 = i["w2"] * applicable
                ubia = i["ubia"] * applicable
            else:
                w2, ubia = i["w2"], i["ubia"]
            tentative = q * rate
            wage_limit = max(w2 * Decimal("0.5"), w2 * Decimal("0.25") + ubia * Decimal("0.025"))
            if excess_ratio > 0 and tentative > wage_limit:
                tentative -= (tentative - wage_limit) * excess_ratio
            comp += tentative
        comp += sum((D(d.section_199a) for d in inc.dividends), ZERO) * rate
        cap = pos(ti_before_qbi - (qd + ncg)) * rate
        qbi_ded = pos(min(comp, cap))
        if comp < 0:
            c.issue("qbi_loss_carryforward", "Net QBI is negative; the loss carries forward (Form 8995).", "info")
    c.set("f1040.13a", qbi_ded, "IRC199A", line=("1040", "13a", "Qualified business income deduction"))
    c.set("f1040.13b", one_a, "Schedule1-A", ["sch1a.38"], line=("1040", "13b", "Schedule 1-A deductions"))
    c.set("f1040.14", c.get("f1040.12") + qbi_ded + one_a, "sum", ["f1040.12", "f1040.13a", "f1040.13b"], line=("1040", "14", "Total deductions"))
    ti = c.set("f1040.15", pos(agi - c.get("f1040.14")), "IRC63", ["f1040.11", "f1040.14"], line=("1040", "15", "Taxable income"))

    # ---------------- Tax (Qualified Dividends & Capital Gain / Schedule D worksheets) ----------------
    reg_tax_all = bracket_tax(ti, brackets)
    pref = min(ti, qd + ncg)
    ancg = pos(min(ti, qd + ncg - g28 - g25))
    if pref > 0:
        t0, t15 = (D(x) for x in pack.by_status("cg_thresholds", sk))
        base_ord = pos(ti - pref)
        tax_ord = bracket_tax(base_ord, brackets)
        stack_start = pos(ti - ancg)
        zero_part = pos(min(ti, t0) - stack_start)
        fifteen_part = pos(min(ti, t15) - max(stack_start, t0))
        twenty_part = pos(ancg - zero_part - fifteen_part)
        tax_pref = fifteen_part * Decimal("0.15") + twenty_part * Decimal("0.20")
        tax_special = min(g25, pos(pref - ancg)) * pack.d("unrecaptured_1250_rate") + min(g28, pos(pref - ancg - g25)) * pack.d("collectibles_rate")
        tax = min(reg_tax_all, tax_ord + tax_pref + tax_special)
        if g25 or g28:
            c.issue("schd_worksheet", "28%/25% gains use a simplified Schedule D Tax Worksheet; review line 18/19 amounts.", "warning")
        note = f"QDCG worksheet: ordinary {whole(base_ord)}, 0% {whole(zero_part)}, 15% {whole(fifteen_part)}, 20% {whole(twenty_part)}"
    else:
        tax = reg_tax_all
        note = "Tax rate schedule"
    if ti < 100000:
        note += "; computed from rate schedule (IRS Tax Table midpoints may differ by a few dollars below $100,000)"
    regular_tax = c.set("f1040.16", tax, "IRC1", ["f1040.15", "f1040.3a"], note=note, line=("1040", "16", "Tax"))

    # ---------------- AMT (Form 6251) ----------------
    amt_rule = pack.rule("amt")["value"]
    addback = salt if use_itemized else c.get("f1040.12")
    amti = ti + addback + D(t.amt.iso_bargain_element) + D(t.amt.private_activity_bond_interest) + D(t.amt.other_adjustments)
    c.set("f6251.4", amti, "Form6251.4", note="AMTI (taxes or standard deduction added back, ISO and other preferences)", line=("Form 6251", "4", "Alternative minimum taxable income"))
    ex = D(amt_rule["exemption"][fs if fs in amt_rule["exemption"] else "single"])
    ps = D(amt_rule["phaseout_start"][fs if fs in amt_rule["phaseout_start"] else "single"])
    ex_eff = pos(ex - pos(amti - ps) * D(amt_rule["phaseout_rate"]))
    amt_base = pos(amti - ex_eff)
    brk = D(amt_rule["rate_break"]["mfs" if fs == "mfs" else "default"])

    def amt_rate(x: Decimal) -> Decimal:
        return x * Decimal("0.26") if x <= brk else brk * Decimal("0.26") + (x - brk) * Decimal("0.28")

    if pref > 0 and amt_base > 0:
        p_amt = min(amt_base, ancg)
        t0, t15 = (D(x) for x in pack.by_status("cg_thresholds", sk))
        start = pos(amt_base - p_amt)
        z = pos(min(amt_base, t0) - start)
        f = pos(min(amt_base, t15) - max(start, t0))
        tw = pos(p_amt - z - f)
        tmt = min(amt_rate(amt_base), amt_rate(pos(amt_base - p_amt)) + f * Decimal("0.15") + tw * Decimal("0.20"))
    else:
        tmt = amt_rate(amt_base)
    ftc = D(t.credits.foreign_tax)
    amt = pos(tmt - (regular_tax - ftc))
    c.set("f6251.11", amt, "IRC55", ["f6251.4", "f1040.16"], note=f"Exemption {whole(ex_eff)}; tentative minimum tax {whole(tmt)}", line=("Form 6251", "11", "Alternative minimum tax"))
    c.set("sch2.3", amt, "Form6251.11", line=("Schedule 2", "3", "AMT and excess APTC"))
    c.set("f1040.17", amt, "Schedule2.3", line=("1040", "17", "Amount from Schedule 2, line 3"))
    tax18 = c.set("f1040.18", regular_tax + amt, "sum", ["f1040.16", "f1040.17"], line=("1040", "18", "Tax plus AMT"))

    # ---------------- Credits ----------------
    ctc_rule = pack.rule("ctc")["value"]
    n_ctc = sum(1 for d in t.dependents if d.ctc and d.age < 17)
    n_odc = sum(1 for d in t.dependents if (d.odc or (not d.ctc) or d.age >= 17))
    gross_credit = D(ctc_rule["per_child"]) * n_ctc + D(ctc_rule["odc"]) * n_odc
    ps_ctc = D(ctc_rule["phaseout_start"]["mfj" if fs == "mfj" else "default"])
    reduction = (pos(agi - ps_ctc) / D(ctc_rule["phaseout_step"])).to_integral_value(ROUND_CEILING) * D(ctc_rule["phaseout_amount"])
    ctc_after = pos(gross_credit - reduction)
    other_nonref = ftc + D(t.credits.child_care) + D(t.credits.education) + D(t.credits.energy) + D(t.credits.other_nonrefundable)
    if ftc > (600 if fs == "mfj" else 300):
        c.issue("form_1116", "Foreign tax above the de minimis election requires Form 1116 limitation; credit used as entered.", "warning")
    nonref_capacity = pos(tax18 - other_nonref)
    ctc_nonref = min(ctc_after, nonref_capacity)
    c.set("f1040.19", ctc_nonref, "IRC24", note=f"{n_ctc} CTC child(ren), {n_odc} other dependent(s); phase-out reduction {whole(reduction)}", line=("1040", "19", "Child tax credit / credit for other dependents"))
    amt_credit = ZERO
    if D(t.carryforwards.amt_credit) > 0 and amt == 0:
        amt_credit = min(D(t.carryforwards.amt_credit), pos(regular_tax - ftc - tmt))
    sch3 = c.set("sch3.8", min(other_nonref + amt_credit, tax18), "Schedule3", note="Foreign tax, dependent care, education, energy, AMT credit", line=("Schedule 3", "8", "Nonrefundable credits"))
    c.set("f1040.20", sch3, "Schedule3.8", line=("1040", "20", "Amount from Schedule 3, line 8"))
    c.set("f1040.21", ctc_nonref + sch3, "sum", line=("1040", "21", "Total credits"))
    c.set("f1040.22", pos(tax18 - ctc_nonref - sch3), "IRC", ["f1040.18", "f1040.21"], line=("1040", "22", "Tax after credits"))

    # ---------------- Other taxes ----------------
    am = pack.rule("addl_medicare")["value"]
    med_wages = sum((D(w.box5) for w in inc.wages), ZERO)
    se_med_base = sum((pos(v) * se_factor for v in se_by_person.values()), ZERO)
    thr = D(am["threshold"][fs])
    addl_med = (pos(med_wages - thr) + pos(se_med_base - pos(thr - med_wages))) * D(am["rate"])
    c.set("f8959.18", addl_med, "IRC3101(b)(2)", note="0.9% on Medicare wages and SE income above threshold", line=("Form 8959", "18", "Additional Medicare Tax"))
    addl_withheld = pos(sum((D(w.medicare_tax) for w in inc.wages), ZERO) - med_wages * pack.d("payroll", None, "medicare_rate"))
    c.set("f8959.24", addl_withheld, "Form8959.24", note="Additional Medicare Tax withheld by employers")

    niit_rule = pack.rule("niit")["value"]
    nii = c.get("f1040.2b") + c.get("f1040.3b") + pos(c.get("f1040.7")) + passive_net - inv_int
    if nonpassive_rental:
        c.issue("niit_rental", "Non-passive rental income was excluded from NIIT; confirm the activity is a trade or business.", "info")
    nii = pos(nii)
    niit = min(nii, pos(agi - D(niit_rule["threshold"][fs]))) * D(niit_rule["rate"])
    c.set("f8960.17", niit, "IRC1411", note=f"Net investment income {whole(nii)}", line=("Form 8960", "17", "Net investment income tax"))
    other_taxes = c.set("sch2.21", c.get("schse.12") + c.get("f8959.18") + c.get("f8960.17"), "sum", ["schse.12", "f8959.18", "f8960.17"],
                        line=("Schedule 2", "21", "Total other taxes"))
    c.set("f1040.23", other_taxes, "Schedule2.21", line=("1040", "23", "Other taxes"))
    total_tax = c.set("f1040.24", c.get("f1040.22") + other_taxes, "sum", ["f1040.22", "f1040.23"], line=("1040", "24", "Total tax"))

    # ---------------- Payments ----------------
    p = t.payments
    w2_wh = sum((D(w.fed_wh) for w in inc.wages), ZERO)
    c.set("f1040.25a", w2_wh, "W2.box2", facts=_facts(inc.wages), line=("1040", "25a", "Federal withholding from W-2"))
    c.set("f1040.25b", D(p.federal_other_withholding), "1099", line=("1040", "25b", "Withholding from Forms 1099"))
    c.set("f1040.25c", addl_withheld, "Form8959.24", line=("1040", "25c", "Other withholding"))
    wh = c.set("f1040.25d", w2_wh + D(p.federal_other_withholding) + addl_withheld, "sum", line=("1040", "25d", "Total withholding"))
    est = c.set("f1040.26", D(p.federal_estimated) + D(p.prior_year_overpayment_federal), "1040-ES", line=("1040", "26", "Estimated tax payments"))
    actc = ZERO
    if ctc_after > ctc_nonref and n_ctc:
        earned = wages + pos(sched_c_total)
        actc = min(ctc_after - ctc_nonref, D(ctc_rule["refundable_max"]) * n_ctc, pos(earned - D(ctc_rule["earned_floor"])) * D(ctc_rule["earned_rate"]))
    c.set("f1040.28", actc, "Schedule8812", line=("1040", "28", "Additional child tax credit"))
    excess_ss = ZERO
    for person in {w.person for w in inc.wages}:
        ws = [w for w in inc.wages if w.person == person]
        if len(ws) > 1:
            withheld = sum((D(w.ss_tax) for w in ws), ZERO)
            excess_ss += pos(withheld - ss_base * pack.d("payroll", None, "ss_rate"))
    c.set("sch3.11", excess_ss, "Schedule3.11", note="Excess Social Security withheld (multiple employers)", line=("Schedule 3", "11", "Excess social security tax withheld"))
    c.set("sch3.15", c.get("sch3.11") + D(p.federal_extension), "sum", line=("Schedule 3", "15", "Other payments and refundable credits"))
    c.set("f1040.31", c.get("sch3.15"), "Schedule3.15", line=("1040", "31", "Amount from Schedule 3, line 15"))
    payments = c.set("f1040.33", wh + est + actc + c.get("f1040.31"), "sum", ["f1040.25d", "f1040.26", "f1040.28", "f1040.31"], line=("1040", "33", "Total payments"))
    diff = payments - total_tax
    c.set("f1040.34", pos(diff), "IRC", line=("1040", "34", "Overpaid"))
    c.set("f1040.37", pos(-diff), "IRC", line=("1040", "37", "Amount you owe"))

    # ---------------- Estimated tax safe harbor ----------------
    et = pack.rule("estimated_tax")["value"]
    current_req = total_tax * D(et["current_year_pct"])
    prior_req = None
    if t.prior_year.federal_total_tax is not None:
        high = D(t.prior_year.federal_agi or 0) > D(et["high_agi"]["mfs" if fs == "mfs" else "default"])
        prior_req = D(t.prior_year.federal_total_tax) * D(et["prior_year_high_pct" if high else "prior_year_pct"])
    else:
        c.issue("prior_year_missing", "Prior-year tax not provided; safe harbor uses 90% of current-year tax only.", "info")
    required = min(current_req, prior_req) if prior_req is not None else current_req
    c.set("f2210.9", required, "IRC6654(d)", note="Required annual payment", line=("Form 2210", "9", "Required annual payment"))
    timely = wh + est + excess_ss
    penalty = ZERO
    if pos(-diff) >= D(et["min_balance"]) and timely < required:
        shortfall = required - timely
        penalty = shortfall * D(et["underpayment_rate"]) * Decimal("0.5")
        c.issue("underpayment", f"Payments {whole(timely)} are below the required annual payment {whole(required)}; Form 2210 penalty estimated.", "warning")
    c.set("f1040.38", penalty, "IRC6654", note="Estimated using even installments and a half-year average period", line=("1040", "38", "Estimated tax penalty (estimate)"))

    marg = marginal_rate(ti, brackets)
    summary = {
        "agi": str(agi), "taxable_income": str(ti), "deduction_type": "itemized" if use_itemized else "standard",
        "deduction": str(c.get("f1040.12")), "regular_tax": str(regular_tax), "amt": str(amt), "credits": str(c.get("f1040.21")),
        "other_taxes": str(other_taxes), "total_tax": str(total_tax), "withholding": str(wh), "estimated_payments": str(est),
        "refundable_credits": str(actc + excess_ss), "total_payments": str(payments), "refund": str(c.get("f1040.34")),
        "balance_due": str(c.get("f1040.37")), "penalty_estimate": str(penalty), "marginal_rate": str(marg),
        "effective_rate": str((total_tax / agi).quantize(Decimal("0.0001"))) if agi > 0 else "0",
        "required_annual_payment": str(c.get("f2210.9")), "niit": str(c.get("f8960.17")), "se_tax": str(se_tax),
        "additional_medicare": str(c.get("f8959.18")), "qbi_deduction": str(qbi_ded),
        "carryforwards": {"capital_loss_st": str(whole(carry_st)), "capital_loss_lt": str(whole(carry_lt)), "passive_suspended": str(whole(suspended))},
    }
    return {"calc": c, "summary": summary, "rentals": rentals_out,
            "shared": {"agi": agi, "wages": wages, "sched_c": sched_c_total, "rentals": rentals_out, "qd": qd, "od": od,
                       "fed_ti": ti, "marginal": marg, "ncg": ncg}}
