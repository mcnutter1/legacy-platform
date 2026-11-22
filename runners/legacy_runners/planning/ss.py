"""Social Security claiming adjustments and RMD tables (planning approximations, documented per function)."""
from __future__ import annotations

# IRS Uniform Lifetime Table (Reg. 1.401(a)(9)-9(c), effective 2022).
UNIFORM_LIFETIME = {
    72: 27.4, 73: 26.5, 74: 25.5, 75: 24.6, 76: 23.7, 77: 22.9, 78: 22.0, 79: 21.1, 80: 20.2, 81: 19.4, 82: 18.5, 83: 17.7,
    84: 16.8, 85: 16.0, 86: 15.2, 87: 14.4, 88: 13.7, 89: 12.9, 90: 12.2, 91: 11.5, 92: 10.8, 93: 10.1, 94: 9.5, 95: 8.9,
    96: 8.4, 97: 7.8, 98: 7.3, 99: 6.8, 100: 6.4, 101: 6.0, 102: 5.6, 103: 5.2, 104: 4.9, 105: 4.6, 106: 4.3, 107: 4.1,
    108: 3.9, 109: 3.7, 110: 3.5, 111: 3.4, 112: 3.3, 113: 3.1, 114: 3.0, 115: 2.9, 116: 2.8, 117: 2.7, 118: 2.5, 119: 2.3, 120: 2.0,
}


def rmd_start_age(birth_year: int) -> int:
    """SECURE 2.0: 73 for those born 1951-1959, 75 for 1960 or later."""
    if birth_year <= 1950:
        return 72
    return 73 if birth_year <= 1959 else 75


def rmd_divisor(age: int) -> float:
    return UNIFORM_LIFETIME.get(min(age, 120), 2.0)


def fra_months(birth_year: int) -> int:
    """Full retirement age in months (SSA): 66 for 1943-54, +2 months/yr 1955-59, 67 for 1960+."""
    if birth_year <= 1954:
        return 66 * 12
    if birth_year >= 1960:
        return 67 * 12
    return 66 * 12 + (birth_year - 1954) * 2


def claim_factor(birth_year: int, claim_age_months: int) -> float:
    """Retirement benefit as a fraction of PIA: 5/9% per month for the first 36 early months, 5/12% beyond;
    delayed retirement credits of 2/3% per month (8%/yr) up to age 70."""
    fra = fra_months(birth_year)
    claim = max(62 * 12, min(70 * 12, claim_age_months))
    if claim < fra:
        early = fra - claim
        return 1 - (min(early, 36) * 5 / 900) - (max(0, early - 36) * 5 / 1200)
    return 1 + (claim - fra) * (2 / 300)


def spousal_factor(birth_year: int, claim_age_months: int) -> float:
    """Spousal benefit fraction of the worker's PIA (max 50%): 25/36% per month first 36 early months, 5/12% beyond; no delayed credits."""
    fra = fra_months(birth_year)
    claim = max(62 * 12, min(fra, claim_age_months))
    early = fra - claim
    return 0.5 * (1 - (min(early, 36) * 25 / 3600) - (max(0, early - 36) * 5 / 1200))
