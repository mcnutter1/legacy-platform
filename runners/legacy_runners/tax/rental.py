"""Vacation-home / short-term rental classification (IRC 280A, 469; Pub. 527, Pub. 925).

Day-count tests are evaluated independently from availability: available-but-unrented days never count
as rental days or personal days.
"""
from __future__ import annotations

from decimal import Decimal
from typing import Any

from .calc import ZERO, D, cents, pos

TIER1 = ("mortgage_interest", "property_tax", "casualty")
TIER2_EXCLUDE = TIER1


def classify(r: Any) -> dict[str, Any]:
    frd = int(r.fair_rental_days)
    pdays = int(r.personal_days)
    total_use = frd + pdays
    stays = max(0, int(getattr(r, "guest_stays", 0)))
    nights = int(getattr(r, "rental_nights", frd))
    avg_stay = (Decimal(nights) / Decimal(stays)).quantize(Decimal("0.01")) if stays else None

    residence_threshold = max(14, int((Decimal(frd) * Decimal("0.10")).to_integral_value(rounding="ROUND_FLOOR")))
    used_as_residence = pdays > residence_threshold
    reasons: list[str] = []

    if used_as_residence and frd < 15:
        category = "minimal_rental"
        reasons.append(f"Rented {frd} fair-rental days (<15) and used as a residence ({pdays} personal days > {residence_threshold}): "
                       "rental income is excluded and rental expenses are not deductible (IRC 280A(g); Pub. 527).")
    elif used_as_residence:
        category = "residence_rental"
        reasons.append(f"Personal use of {pdays} days exceeds the greater of 14 days or 10% of {frd} fair-rental days ({residence_threshold}): "
                       "expenses are limited to rental income with tiered ordering and carryover (IRC 280A(c)(5)).")
    else:
        category = "rental"
        reasons.append(f"Personal use of {pdays} days does not exceed {residence_threshold}: property is not used as a residence; "
                       "allocated expenses may create a loss subject to at-risk and passive rules.")

    if frd == 0 and pdays == 0:
        category = "no_use"
        reasons = ["No fair-rental or personal use days recorded; availability alone does not establish rental use."]

    # Passive activity characterization (Reg. 1.469-1T(e)(3)(ii)).
    activity = "rental_activity"
    if avg_stay is not None and avg_stay <= 7:
        activity = "not_rental_activity_short_stay"
        reasons.append(f"Average customer use of {avg_stay} days is 7 or fewer: not a 'rental activity' under Reg. 1.469-1T(e)(3)(ii)(A); "
                       "treated as a trade or business that is passive unless the owner materially participates.")
    elif avg_stay is not None and avg_stay <= 30 and getattr(r, "significant_services", False):
        activity = "not_rental_activity_services"
        reasons.append("Average stay of 30 days or fewer with significant personal services: not a rental activity (Reg. 1.469-1T(e)(3)(ii)(B)).")

    material = bool(getattr(r, "material_participation", False))
    if activity == "rental_activity":
        passive = True
        loss_rule = "special_allowance" if getattr(r, "active_participation", False) else "passive"
    else:
        passive = not material
        loss_rule = "nonpassive" if material else "passive"
    if activity != "rental_activity":
        reasons.append("Material participation: " + ("documented — losses are non-passive." if material else "not documented — losses are passive with no $25,000 allowance."))

    ratio = (Decimal(frd) / Decimal(total_use)) if total_use else ZERO
    bolton_ratio = (Decimal(frd) / Decimal(365))
    return {
        "category": category,
        "activity": activity,
        "passive": passive,
        "loss_rule": loss_rule,
        "fair_rental_days": frd,
        "personal_days": pdays,
        "available_days": int(getattr(r, "available_days", 0)),
        "residence_threshold": residence_threshold,
        "used_as_residence": used_as_residence,
        "average_stay_days": str(avg_stay) if avg_stay is not None else None,
        "rental_ratio": str(ratio.quantize(Decimal("0.000001"))),
        "bolton_ratio": str(bolton_ratio.quantize(Decimal("0.000001"))),
        "reasons": reasons,
        "authorities": [
            {"cite": "IRC 280A(d)(1), (g), (c)(5), (e)", "url": "https://www.law.cornell.edu/uscode/text/26/280A"},
            {"cite": "Pub. 527 ch. 5", "url": "https://www.irs.gov/publications/p527"},
            {"cite": "Reg. 1.469-1T(e)(3)", "url": "https://www.law.cornell.edu/cfr/text/26/1.469-1T"},
            {"cite": "Pub. 925", "url": "https://www.irs.gov/publications/p925"},
        ],
    }


def schedule_e(r: Any, cls: dict[str, Any]) -> dict[str, Any]:
    """Allocate expenses and apply the 280A(c)(5) limitation. Returns net income/loss before passive limits."""
    rents = D(r.rents)
    exp = {k: D(v) for k, v in r.expenses.items()}
    depreciation = D(r.depreciation)
    if cls["category"] in ("minimal_rental", "no_use"):
        # Interest/taxes remain potentially deductible on Schedule A in full.
        return {"rents": "0", "deductible_expenses": "0", "net": "0", "excluded_income": str(cents(rents)),
                "schedule_a_interest": str(cents(exp.get("mortgage_interest", ZERO))), "schedule_a_taxes": str(cents(exp.get("property_tax", ZERO))),
                "carryover_280a": "0", "depreciation_allowed": "0"}

    ratio = D(cls["rental_ratio"])
    tier1_ratio = D(cls["bolton_ratio"]) if getattr(r, "allocation_method", "irs") == "bolton" and cls["category"] == "residence_rental" else ratio
    tier1 = sum((exp.get(k, ZERO) for k in TIER1), ZERO) * tier1_ratio
    direct = exp.get("platform_fees", ZERO) + exp.get("advertising", ZERO) + exp.get("cleaning_turnover", ZERO)
    tier2 = sum((v for k, v in exp.items() if k not in TIER1 and k not in ("platform_fees", "advertising", "cleaning_turnover")), ZERO) * ratio + direct
    tier3 = depreciation * ratio
    personal_interest = exp.get("mortgage_interest", ZERO) - exp.get("mortgage_interest", ZERO) * tier1_ratio
    personal_taxes = exp.get("property_tax", ZERO) - exp.get("property_tax", ZERO) * tier1_ratio

    if cls["category"] == "residence_rental":
        remaining = rents - tier1
        t2 = min(tier2 + D(getattr(r, "prior_280a_carryover", 0)), pos(remaining))
        remaining -= t2
        t3 = min(tier3, pos(remaining))
        carry = (tier2 + D(getattr(r, "prior_280a_carryover", 0)) - t2) + (tier3 - t3)
        deductible = tier1 + t2 + t3
        net = rents - deductible
        return {"rents": str(cents(rents)), "tier1": str(cents(tier1)), "tier2_allowed": str(cents(t2)), "tier3_allowed": str(cents(t3)),
                "deductible_expenses": str(cents(deductible)), "net": str(cents(net)), "carryover_280a": str(cents(pos(carry))),
                "depreciation_allowed": str(cents(t3)), "schedule_a_interest": str(cents(personal_interest)), "schedule_a_taxes": str(cents(personal_taxes))}

    deductible = tier1 + tier2 + tier3
    return {"rents": str(cents(rents)), "tier1": str(cents(tier1)), "deductible_expenses": str(cents(deductible)), "net": str(cents(rents - deductible)),
            "carryover_280a": "0", "depreciation_allowed": str(cents(tier3)),
            "schedule_a_interest": str(cents(personal_interest)), "schedule_a_taxes": str(cents(personal_taxes))}


def run(inp: dict[str, Any]) -> dict[str, Any]:
    from .model import Rental

    out = []
    for raw in inp.get("rentals", []):
        r = Rental.model_validate(raw)
        cls = classify(r)
        out.append({"property_id": r.property_id, "name": r.name, "classification": cls, "schedule_e": schedule_e(r, cls)})
    return {"rentals": out}
