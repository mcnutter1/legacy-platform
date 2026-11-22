from __future__ import annotations

import os
from decimal import Decimal

import pytest

from legacy_runners import debt, estate, imports, equity


def test_golden_amortization():
    res = debt.run_amortize({"loan": {"id": "m", "balance": "200000", "rate": "0.06", "term_months": 360, "first_due": "2026-01-01"}})
    base = res["baseline"]
    assert base["payment"] == "1199.10"
    assert base["months"] == 360
    assert Decimal(base["rows"][-1]["balance"]) == 0
    assert abs(Decimal(base["total_interest"]) - Decimal("231676.38")) < Decimal("5")


def test_extra_principal_saves_interest():
    res = debt.run_amortize({"loan": {"id": "m", "balance": "200000", "rate": "0.06", "term_months": 360, "first_due": "2026-01-01", "extra_monthly": "200"}})
    assert Decimal(res["interest_saved"]) > 50000
    assert res["with_extra"]["months"] < 300


def test_negative_amortization_detected():
    res = debt.amortize(debt.Loan(id="n", balance=Decimal(100000), rate=Decimal("0.12"), payment=Decimal(500), first_due="2026-01-01"))
    assert res["negative_amortization"] is True


def test_avalanche_beats_snowball_on_interest():
    loans = [
        {"id": "card", "balance": "8000", "rate": "0.24", "payment": "250", "first_due": "2026-01-01"},
        {"id": "auto", "balance": "3000", "rate": "0.05", "payment": "150", "first_due": "2026-01-01"},
    ]
    res = debt.run_compare({"loans": loans, "extra_monthly": "300"})
    s = res["strategies"]
    assert Decimal(s["avalanche"]["total_interest"]) <= Decimal(s["snowball"]["total_interest"])
    assert Decimal(s["avalanche"]["interest_saved_vs_minimum"]) > 0


FAMILY = {
    "year": 2026,
    "persons": [{"id": "h", "name": "H"}, {"id": "w", "name": "W"}, {"id": "k1", "name": "Kid1", "age": 30}, {"id": "k2", "name": "Kid2", "age": 16}],
    "relations": [{"from_id": "h", "to_id": "w", "kind": "spouse"}, {"from_id": "h", "to_id": "k1", "kind": "child"},
                  {"from_id": "w", "to_id": "k1", "kind": "child"}, {"from_id": "h", "to_id": "k2", "kind": "child"}, {"from_id": "w", "to_id": "k2", "kind": "child"}],
    "assets": [
        {"id": "home", "label": "Residence", "kind": "property", "value": "1200000", "basis": "600000", "debt": "400000",
         "title_form": "tenancy_by_entirety", "owners": [{"type": "person", "id": "h", "fraction": "0.5"}, {"type": "person", "id": "w", "fraction": "0.5"}]},
        {"id": "ira", "label": "H IRA", "kind": "account", "value": "900000", "retirement": True, "title_form": "sole",
         "owners": [{"type": "person", "id": "h", "fraction": "1"}],
         "beneficiaries": [{"tier": "primary", "type": "person", "id": "w", "share": "1"},
                           {"tier": "contingent", "type": "person", "id": "k1", "share": "0.5"}, {"tier": "contingent", "type": "person", "id": "k2", "share": "0.5"}]},
        {"id": "brk", "label": "H brokerage", "kind": "account", "value": "2000000", "basis": "800000", "title_form": "sole",
         "owners": [{"type": "person", "id": "h", "fraction": "1"}]},
        {"id": "life", "label": "Term life", "kind": "policy", "value": "0", "life_insurance": {"insured": "h", "owner": "h", "death_benefit": "3000000"},
         "beneficiaries": [{"tier": "primary", "type": "person", "id": "w", "share": "1"}]},
    ],
    "wills": [{"person_id": "h", "residuary": [{"tier": "primary", "type": "person", "id": "w", "share": "1"}]},
              {"person_id": "w", "residuary": [{"tier": "primary", "type": "person", "id": "k1", "share": "0.5"}, {"tier": "primary", "type": "person", "id": "k2", "share": "0.5"}]}],
    "exemption": "15000000",
}


def test_first_death_marital_deduction_no_tax():
    res = estate.run({**FAMILY, "death_order": ["h"]})
    d = res["deaths"][0]
    assert Decimal(d["federal_estate_tax"]) == 0
    assert Decimal(d["marital_deduction"]) > 0
    assert Decimal(d["dsue_carried"]) > 0
    assert any(u["code"] == "minor_beneficiary" for u in res["unresolved"])


def test_second_death_uses_portability_and_class_a_exempt():
    res = estate.run({**FAMILY, "death_order": ["h", "w"]})
    second = res["deaths"][1]
    assert Decimal(second["applicable_exclusion"]) > Decimal(15000000)
    assert Decimal(second["nj_inheritance_tax"]) == 0
    kids = {b["recipient_id"]: Decimal(b["amount"]) for b in res["beneficiaries"]}
    assert kids["k1"] > 0 and kids["k2"] > 0


def test_nj_class_d_inheritance_tax():
    data = {"year": 2026, "death_order": ["a"], "persons": [{"id": "a", "name": "A"}, {"id": "f", "name": "Friend"}],
            "assets": [{"id": "x", "label": "Cash", "kind": "account", "value": "100000", "owners": [{"type": "person", "id": "a", "fraction": "1"}],
                        "beneficiaries": [{"tier": "primary", "type": "person", "id": "f", "share": "1"}]}],
            "funeral": "0"}
    res = estate.run(data)
    assert Decimal(res["deaths"][0]["nj_inheritance_tax"]) == Decimal("15000.00")


def test_monarch_csv_normalization(tmp_path, monkeypatch):
    monkeypatch.setenv("LEGACY_JOB_DIR", str(tmp_path))
    f = tmp_path / "m.csv"
    f.write_text("Date,Merchant,Category,Account,Original Statement,Notes,Amount,Tags\n"
                 "2026-01-03,Coffee Co,Restaurants,Chase Sapphire,COFFEE CO 123,,-4.50,\n"
                 "2026-01-03,Coffee Co,Restaurants,Chase Sapphire,COFFEE CO 123,,-4.50,\n"
                 "2026-01-05,Payroll,Paychecks,Checking,ACME PAYROLL,,3500.00,work\n"
                 "bad-date,X,Y,Checking,,,-1,\n")
    res = imports.run({"provider": "monarch", "file_path": str(f), "profile": {"account_map": {"Chase Sapphire": "A1", "Checking": "A2"}}})
    rows = res["rows"]
    assert len(rows) == 4
    assert rows[0]["fingerprint"] != rows[1]["fingerprint"]  # identical rows remain distinct events
    assert rows[2]["normalized"]["kind"] == "income"
    assert "unparseable_date" in rows[3]["issues"]
    # Re-import yields identical fingerprints (replay is a no-op downstream).
    again = imports.run({"provider": "monarch", "file_path": str(f), "profile": {"account_map": {"Chase Sapphire": "A1", "Checking": "A2"}}})
    assert [r["fingerprint"] for r in again["rows"]] == [r["fingerprint"] for r in rows]


def test_snowball_csv_and_schema_drift(tmp_path, monkeypatch):
    monkeypatch.setenv("LEGACY_JOB_DIR", str(tmp_path))
    f = tmp_path / "s.csv"
    f.write_text("Event,Date,Symbol,Price,Quantity,Currency,FeeTax,Exchange,NKD,FeeCurrency,DoNotAdjustCash,Note\n"
                 "BUY,2026-02-01,VTI,250.00,10,USD,1.00,,,,,\n"
                 "DIVIDEND,2026-03-15,VTI,12.34,,USD,0,,,,,\n")
    res = imports.run({"provider": "snowball", "file_path": str(f), "profile": {"default_account_label": "Main", "account_map": {"Main": "B1"}}})
    assert res["rows"][0]["normalized"]["amount"] == "-2501.00"
    assert res["rows"][1]["normalized"]["kind"] == "dividend"
    g = tmp_path / "bad.csv"
    g.write_text("When,What\n2026-01-01,x\n")
    drift = imports.run({"provider": "snowball", "file_path": str(g)})
    assert drift["ok_schema"] is False and drift["schema_drift"]["missing_columns"]


def test_job_path_confinement(tmp_path, monkeypatch):
    monkeypatch.setenv("LEGACY_JOB_DIR", str(tmp_path / "job"))
    os.makedirs(tmp_path / "job")
    with pytest.raises(ValueError):
        imports.run({"provider": "monarch", "file_path": "/etc/passwd"})


def test_waterfall_non_participating_converts():
    wf = {"common_shares": "8000000", "preferred": [{"name": "A", "shares": "2000000", "preference": "10000000"}], "fees_pct": "0"}
    low = equity.waterfall(equity.Waterfall.model_validate(wf), Decimal(10000000))
    high = equity.waterfall(equity.Waterfall.model_validate(wf), Decimal(200000000))
    assert Decimal(low["common_per_share"]) == 0
    assert "A" in high["converting"] and Decimal(high["common_per_share"]) == Decimal("20.00")
