import { describe, expect, it } from 'vitest';
import { date, humanize, money, pct } from '../lib/format';
import { recordPath } from '../lib/paths';

describe('format', () => {
  it('formats money without float drift and handles blanks', () => {
    expect(money('1234567.891')).toBe('$1,234,568');
    expect(money('12.5', { cents: true })).toBe('$12.50');
    expect(money(null)).toBe('—');
    expect(money('not-a-number')).toBe('—');
    expect(money(-50, { sign: true })).toBe('-$50');
  });

  it('formats percentages and dates', () => {
    expect(pct('0.2346')).toBe('23.5%');
    expect(date('2025-04-15')).toBe('Apr 15, 2025');
    expect(date(null)).toBe('—');
  });

  it('humanizes identifiers', () => {
    expect(humanize('nj_inheritance_tax')).toBe('NJ inheritance tax');
    expect(humanize('capital_loss_st')).toBe('Capital loss st');
  });
});

describe('recordPath', () => {
  it('routes detail types and falls back to sections', () => {
    expect(recordPath('accounts', 'X')).toBe('accounts/X');
    expect(recordPath('tax_run', 'R')).toBe('tax/runs/R');
    expect(recordPath('liabilities', 'L')).toBe('debts');
    expect(recordPath('unknown_type', 'U')).toBe('records/unknown_type');
  });
});
