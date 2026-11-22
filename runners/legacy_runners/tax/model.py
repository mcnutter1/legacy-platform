"""Typed tax input. Money is accepted as strings/numbers and converted to Decimal."""
from __future__ import annotations

from decimal import Decimal
from typing import Literal, Optional

from pydantic import BaseModel, ConfigDict, Field

Money = Decimal


class M(BaseModel):
    model_config = ConfigDict(extra="forbid", coerce_numbers_to_str=False)


class Person(M):
    id: str
    role: Literal["primary", "spouse"]
    age: int = Field(ge=0, le=130)
    blind: bool = False
    dependent_of_another: bool = False


class Dependent(M):
    id: str
    age: int = Field(ge=0, le=130)
    ctc: bool = True
    odc: bool = False
    college_student: bool = False


class Wage(M):
    person: str
    employer: str = ""
    box1: Money = Decimal(0)
    box3: Money = Decimal(0)
    box5: Money = Decimal(0)
    box16_nj: Optional[Money] = None
    fed_wh: Money = Decimal(0)
    nj_wh: Money = Decimal(0)
    ss_tax: Money = Decimal(0)
    medicare_tax: Money = Decimal(0)
    box12: dict[str, Money] = {}
    tips: Money = Decimal(0)
    overtime_premium: Money = Decimal(0)
    facts: list[str] = []


class Interest(M):
    amount: Money = Decimal(0)
    tax_exempt: Money = Decimal(0)
    us_obligations: Money = Decimal(0)
    nj_exempt: Money = Decimal(0)
    facts: list[str] = []


class Dividend(M):
    ordinary: Money = Decimal(0)
    qualified: Money = Decimal(0)
    cap_gain_dist: Money = Decimal(0)
    unrecaptured_1250: Money = Decimal(0)
    section_199a: Money = Decimal(0)
    foreign_tax: Money = Decimal(0)
    exempt_interest: Money = Decimal(0)
    nj_exempt: Money = Decimal(0)
    facts: list[str] = []


class CapitalTxn(M):
    term: Literal["short", "long"]
    proceeds: Money
    basis: Money
    adjustment: Money = Decimal(0)
    collectible: bool = False
    unrecaptured_1250: Money = Decimal(0)
    description: str = ""
    facts: list[str] = []


class ScheduleC(M):
    person: str
    name: str
    gross: Money = Decimal(0)
    returns: Money = Decimal(0)
    cogs: Money = Decimal(0)
    expenses: dict[str, Money] = {}
    depreciation: Money = Decimal(0)
    section_179: Money = Decimal(0)
    home_office: Money = Decimal(0)
    qbi_eligible: bool = True
    sstb: bool = False
    w2_wages: Money = Decimal(0)
    ubia: Money = Decimal(0)
    facts: list[str] = []


class Rental(M):
    property_id: str
    name: str
    rents: Money = Decimal(0)
    expenses: dict[str, Money] = {}
    depreciation: Money = Decimal(0)
    fair_rental_days: int = 0
    personal_days: int = 0
    available_days: int = 0
    guest_stays: int = 0
    rental_nights: int = 0
    material_participation: bool = False
    active_participation: bool = True
    significant_services: bool = False
    allocation_method: Literal["irs", "bolton"] = "irs"
    prior_suspended_loss: Money = Decimal(0)
    prior_280a_carryover: Money = Decimal(0)
    person: Optional[str] = None
    facts: list[str] = []


class K1(M):
    entity: str
    kind: Literal["1065", "1120S", "1041"]
    person: Optional[str] = None
    ordinary: Money = Decimal(0)
    rental: Money = Decimal(0)
    interest: Money = Decimal(0)
    dividends_ordinary: Money = Decimal(0)
    dividends_qualified: Money = Decimal(0)
    st_gain: Money = Decimal(0)
    lt_gain: Money = Decimal(0)
    sec1231: Money = Decimal(0)
    guaranteed_payments: Money = Decimal(0)
    se_earnings: Money = Decimal(0)
    sec179: Money = Decimal(0)
    qbi: Money = Decimal(0)
    w2_wages: Money = Decimal(0)
    ubia: Money = Decimal(0)
    sstb: bool = False
    passive: bool = False
    basis_available: Optional[Money] = None
    at_risk: Optional[Money] = None
    nj_source: Optional[Money] = None
    facts: list[str] = []


class Retirement(M):
    person: str
    gross: Money = Decimal(0)
    taxable: Money = Decimal(0)
    nj_taxable: Optional[Money] = None
    kind: Literal["ira", "pension", "annuity", "401k", "roth"] = "ira"
    facts: list[str] = []


class SocialSecurity(M):
    person: str
    benefits: Money = Decimal(0)
    facts: list[str] = []


class OtherIncome(M):
    label: str
    amount: Money = Decimal(0)
    nj_amount: Optional[Money] = None
    facts: list[str] = []


class Income(M):
    wages: list[Wage] = []
    interest: list[Interest] = []
    dividends: list[Dividend] = []
    capital: list[CapitalTxn] = []
    schedule_c: list[ScheduleC] = []
    rentals: list[Rental] = []
    k1: list[K1] = []
    retirement: list[Retirement] = []
    social_security: list[SocialSecurity] = []
    other: list[OtherIncome] = []
    unemployment: Money = Decimal(0)


class Adjustments(M):
    hsa: Money = Decimal(0)
    ira_deductible: Money = Decimal(0)
    student_loan_interest: Money = Decimal(0)
    se_health_insurance: Money = Decimal(0)
    se_retirement: Money = Decimal(0)
    educator: Money = Decimal(0)
    car_loan_interest: Money = Decimal(0)
    other: Money = Decimal(0)


class Itemized(M):
    medical: Money = Decimal(0)
    state_local_income_tax: Money = Decimal(0)
    real_estate_tax: Money = Decimal(0)
    personal_property_tax: Money = Decimal(0)
    mortgage_interest: Money = Decimal(0)
    mortgage_points: Money = Decimal(0)
    mortgage_principal_outstanding: Money = Decimal(0)
    investment_interest: Money = Decimal(0)
    charitable_cash: Money = Decimal(0)
    charitable_noncash: Money = Decimal(0)
    other: Money = Decimal(0)
    force_itemize: bool = False


class Payments(M):
    federal_estimated: Money = Decimal(0)
    federal_extension: Money = Decimal(0)
    federal_other_withholding: Money = Decimal(0)
    nj_estimated: Money = Decimal(0)
    nj_extension: Money = Decimal(0)
    nj_other_withholding: Money = Decimal(0)
    prior_year_overpayment_federal: Money = Decimal(0)
    prior_year_overpayment_nj: Money = Decimal(0)
    federal_installments: list[Money] = []
    nj_installments: list[Money] = []


class PriorYear(M):
    federal_total_tax: Optional[Money] = None
    federal_agi: Optional[Money] = None
    nj_total_tax: Optional[Money] = None


class Carryforwards(M):
    capital_loss_st: Money = Decimal(0)
    capital_loss_lt: Money = Decimal(0)
    amt_credit: Money = Decimal(0)
    charitable: Money = Decimal(0)
    passive: dict[str, Money] = {}


class Amt(M):
    iso_bargain_element: Money = Decimal(0)
    private_activity_bond_interest: Money = Decimal(0)
    other_adjustments: Money = Decimal(0)


class NJExtra(M):
    property_tax_paid: Money = Decimal(0)
    rent_paid: Money = Decimal(0)
    homeowner: bool = True
    medical_expenses: Optional[Money] = None
    nj_529_contributions: Money = Decimal(0)
    credit_other_jurisdictions: Money = Decimal(0)
    veterans: int = 0


class Credits(M):
    foreign_tax: Money = Decimal(0)
    child_care: Money = Decimal(0)
    education: Money = Decimal(0)
    energy: Money = Decimal(0)
    other_nonrefundable: Money = Decimal(0)


class PackRef(M):
    version: Optional[str] = None
    hash: Optional[str] = None
    approved: bool = False


class TaxInput(M):
    tax_year: int
    filing_status: Literal["single", "mfj", "mfs", "hoh", "qss"]
    state: Literal["NJ", "NONE"] = "NJ"
    residency: Literal["resident", "part_year", "nonresident"] = "resident"
    people: list[Person]
    dependents: list[Dependent] = []
    income: Income = Income()
    adjustments: Adjustments = Adjustments()
    itemized: Itemized = Itemized()
    credits: Credits = Credits()
    payments: Payments = Payments()
    prior_year: PriorYear = PriorYear()
    carryforwards: Carryforwards = Carryforwards()
    amt: Amt = Amt()
    nj: NJExtra = NJExtra()
    packs: dict[str, PackRef] = {}
    missing_inputs: list[str] = []
    assumptions: list[str] = []
    mfs_lived_apart: bool = False
