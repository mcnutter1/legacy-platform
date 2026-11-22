from __future__ import annotations

import copy

import pytest

from legacy_runners.tax.engine import compute, run
from legacy_runners.tax.model import TaxInput
from legacy_runners.tax.rental import classify
from legacy_runners.tax.model import Rental


def line(res, form_line):
    for ln in res["lines"]:
        if f'{ln["form"]}:{ln["line"]}' == form_line:
            return ln["value"]
    raise AssertionError(f"line {form_line} not found")


JOINT_2025 = {
    "tax_year": 2025, "filing_status": "mfj", "state": "NJ",
    "people": [{"id": "p1", "role": "primary", "age": 45}, {"id": "p2", "role": "spouse", "age": 44}],
    "dependents": [{"id": "c1", "age": 8}, {"id": "c2", "age": 10}],
    "income": {
        "wages": [
            {"person": "p1", "employer": "Alpha", "box1": "140000", "box3": "150000", "box5": "150000", "box16_nj": "150000",
             "fed_wh": "20000", "nj_wh": "6000", "ss_tax": "9300", "medicare_tax": "2175", "box12": {"D": "10000"}},
            {"person": "p2", "employer": "Beta", "box1": "90000", "box3": "90000", "box5": "90000", "box16_nj": "90000",
             "fed_wh": "9000", "nj_wh": "3000", "ss_tax": "5580", "medicare_tax": "1305"},
        ],
        "interest": [{"amount": "2000"}],
        "dividends": [{"ordinary": "4000", "qualified": "3000"}],
        "capital": [{"term": "long", "proceeds": "30000", "basis": "20000"}],
    },
    "itemized": {"state_local_income_tax": "9000", "real_estate_tax": "12000", "mortgage_interest": "15000", "charitable_cash": "5000"},
    "nj": {"property_tax_paid": "12000"},
    "prior_year": {"federal_total_tax": "28000", "federal_agi": "230000", "nj_total_tax": "10500"},
    "packs": {"US": {"approved": True}, "NJ": {"approved": True}},
}


def test_issued_joint_return_lines():
    res = run(JOINT_2025)
    assert line(res, "1040:11") == "246000"
    assert line(res, "1040:12") == "41000"
    assert line(res, "1040:15") == "205000"
    assert line(res, "1040:16") == "34018"
    assert line(res, "Form 6251:11") == "0"
    assert line(res, "1040:19") == "4400"
    assert line(res, "1040:24") == "29618"
    assert line(res, "1040:37") == "618"
    assert res["status"] == "calculated"


def test_nj_independent_of_federal_agi():
    res = run(JOINT_2025)
    # NJ wages include the 401(k) deferral excluded federally.
    assert line(res, "NJ-1040:15") == "240000"
    assert line(res, "NJ-1040:29") == "256000"
    assert line(res, "NJ-1040:42") == "239000"
    assert line(res, "NJ-1040:43") == "11182"
    assert line(res, "NJ-1040:67") == "2182"
    assert res["summary"]["nj"]["property_tax_treatment"] == "deduction"


def test_prior_year_rules_are_pinned_and_changed_rules_differ():
    y26 = copy.deepcopy(JOINT_2025)
    y26["tax_year"] = 2026
    r25, r26 = run(JOINT_2025), run(y26)
    assert r25["packs"]["US"]["year"] == 2025 and r26["packs"]["US"]["year"] == 2026
    # 2026: 0.5% AGI floor on itemized charitable gifts (OBBBA).
    assert line(r26, "1040:15") == "206230"
    assert line(r26, "1040:16") == "33885"
    assert r26["status"] == "estimate"  # NJ 2026 pack is provisional


def test_unapproved_pack_caps_status_at_estimate():
    data = copy.deepcopy(JOINT_2025)
    data["packs"] = {}
    res = run(data)
    assert res["status"] == "estimate"
    assert any("pending professional approval" in r for r in res["status_reasons"])


def test_married_filing_separately():
    data = copy.deepcopy(JOINT_2025)
    data["filing_status"] = "mfs"
    data["people"] = [data["people"][0]]
    data["dependents"] = []
    data["income"]["wages"] = [data["income"]["wages"][0]]
    data["itemized"] = {}
    res = run(data)
    # 140000 + 2000 + 4000 + 10000 = 156000 AGI; standard deduction 15750.
    assert line(res, "1040:11") == "156000"
    assert line(res, "1040:15") == "140250"
    # MFS NIIT threshold is 125,000: NII 16,000 fully taxed at 3.8%.
    assert line(res, "Form 8960:17") == "608"


def test_two_employers_excess_social_security():
    data = copy.deepcopy(JOINT_2025)
    data["income"]["wages"] = [
        {"person": "p1", "employer": "A", "box1": "120000", "box3": "120000", "box5": "120000", "fed_wh": "15000", "ss_tax": "7440", "medicare_tax": "1740"},
        {"person": "p1", "employer": "B", "box1": "120000", "box3": "120000", "box5": "120000", "fed_wh": "15000", "ss_tax": "7440", "medicare_tax": "1740"},
    ]
    res = run(data)
    assert line(res, "Schedule 3:11") == "3962"


def test_k1_loss_requires_basis():
    data = copy.deepcopy(JOINT_2025)
    data["income"]["k1"] = [{"entity": "Fund LP", "kind": "1065", "ordinary": "-8000"}]
    res = run(data)
    assert res["status"] == "unsupported"
    data["income"]["k1"][0].update({"basis_available": "5000", "at_risk": "5000"})
    res2 = run(data)
    assert res2["status"] == "calculated"
    assert any(i["code"] == "k1_loss_limited" for i in res2["issues"])


def test_rsu_basis_prevents_double_count():
    data = copy.deepcopy(JOINT_2025)
    data["income"]["wages"][0]["box1"] = "190000"  # includes 50,000 RSU vest income
    data["income"]["capital"] = [{"term": "short", "proceeds": "52000", "basis": "50000", "description": "RSU sale, basis = FMV at vest"}]
    res = run(data)
    assert line(res, "Schedule D:7") == "2000"


def test_capital_loss_carryforward_and_nj_nonconformity():
    data = copy.deepcopy(JOINT_2025)
    data["income"]["capital"] = [{"term": "short", "proceeds": "10000", "basis": "25000"}]
    data["carryforwards"] = {"capital_loss_lt": "4000"}
    res = run(data)
    assert line(res, "1040:7") == "-3000"
    assert res["summary"]["federal"]["carryforwards"]["capital_loss_st"] == "12000"
    assert res["summary"]["federal"]["carryforwards"]["capital_loss_lt"] == "4000"
    # NJ: no carryover and the category floors at zero.
    assert line(res, "NJ-1040:19") == "0"


def test_underpayment_flagged():
    data = copy.deepcopy(JOINT_2025)
    for w in data["income"]["wages"]:
        w["fed_wh"] = "1000"
    res = run(data)
    assert any(i["code"] == "underpayment" for i in res["issues"])
    assert float(line(res, "1040:38")) > 0


def test_ctc_phaseout_rounds_up_per_thousand():
    data = copy.deepcopy(JOINT_2025)
    data["income"]["other"] = [{"label": "Bonus", "amount": "154001"}]  # AGI 400,001
    res = run(data)
    # 1 dollar over the threshold reduces by a full $50.
    assert line(res, "1040:19") == "4350"


@pytest.mark.parametrize("frd,pdays,category", [
    (14, 14, "rental"),          # 14 personal days does not exceed max(14, 10%): not a residence
    (14, 15, "minimal_rental"),  # residence + fewer than 15 rental days: 280A(g)
    (15, 15, "residence_rental"),
    (15, 14, "rental"),
])
def test_vacation_home_day_tests(frd, pdays, category):
    r = Rental(property_id="x", name="Shore", rents="10000", fair_rental_days=frd, personal_days=pdays, available_days=300)
    assert classify(r)["category"] == category


def test_availability_does_not_create_rental_days():
    r = Rental(property_id="x", name="Shore", fair_rental_days=0, personal_days=0, available_days=365)
    assert classify(r)["category"] == "no_use"


@pytest.mark.parametrize("mp,passive", [(True, False), (False, True)])
def test_short_stays_material_participation(mp, passive):
    r = Rental(property_id="x", name="STR", rents="60000", fair_rental_days=200, personal_days=5, guest_stays=50, rental_nights=200,
               material_participation=mp)
    c = classify(r)
    assert c["activity"] == "not_rental_activity_short_stay"
    assert c["passive"] is passive


def test_minimal_rental_excludes_income():
    data = copy.deepcopy(JOINT_2025)
    data["income"]["rentals"] = [{"property_id": "h", "name": "Masters week", "rents": "20000", "fair_rental_days": 10, "personal_days": 60,
                                  "expenses": {"cleaning": "500"}}]
    res = run(data)
    assert line(res, "Schedule 1:5") == "0"


def test_passive_loss_suspended_at_high_income():
    data = copy.deepcopy(JOINT_2025)
    data["income"]["other"] = [{"label": "Bonus", "amount": "200000"}]
    data["income"]["rentals"] = [{"property_id": "r1", "name": "LTR", "rents": "20000", "fair_rental_days": 365, "personal_days": 0,
                                  "expenses": {"mortgage_interest": "18000", "repairs": "4000"}, "depreciation": "10000", "active_participation": True}]
    res = run(data)
    # MAGI > 150k: no special allowance, the 12,000 loss is suspended.
    assert line(res, "Schedule 1:5") == "0"
    assert res["summary"]["federal"]["carryforwards"]["passive_suspended"] == "12000"


def test_part_year_nj_is_unsupported():
    data = copy.deepcopy(JOINT_2025)
    data["residency"] = "part_year"
    assert run(data)["status"] == "unsupported"


def test_trace_references_rules_and_facts():
    data = copy.deepcopy(JOINT_2025)
    data["income"]["wages"][0]["facts"] = ["payroll:01J"]
    res = run(data)
    wages = next(t for t in res["trace"] if t["node"] == "f1040.1a")
    assert wages["rule"] == "W2.box1" and "payroll:01J" in wages["facts"]
