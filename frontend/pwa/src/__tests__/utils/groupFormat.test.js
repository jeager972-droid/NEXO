import { describe, it, expect } from 'vitest';
import { formatGroupName, formatGroupOption } from '@/utils/groupFormat';

describe('formatGroupName', () => {
  it('returns empty string for falsy input', () => {
    expect(formatGroupName('')).toBe('');
    expect(formatGroupName(null)).toBe('');
    expect(formatGroupName(undefined)).toBe('');
  });

  it('converts "7A" -> "7°A"', () => {
    expect(formatGroupName('7A')).toBe('7°A');
  });

  it('converts "6-B" -> "6°B"', () => {
    expect(formatGroupName('6-B')).toBe('6°B');
  });

  it('converts "11A" -> "11°A"', () => {
    expect(formatGroupName('11A')).toBe('11°A');
  });

  it('handles separators like dot and space', () => {
    expect(formatGroupName('6.B')).toBe('6°B');
    expect(formatGroupName('6 B')).toBe('6°B');
  });

  it('trims whitespace', () => {
    expect(formatGroupName('  7A  ')).toBe('7°A');
  });

  it('returns trimmed string when no digit prefix matches', () => {
    expect(formatGroupName('ABC')).toBe('ABC');
  });

  it('coerces numbers to strings', () => {
    expect(formatGroupName(7)).toBe('7');
  });
});

describe('formatGroupOption', () => {
  it('uses g.name when present', () => {
    expect(formatGroupOption({ name: '7A' })).toBe('7°A');
  });

  it('falls back to g.group_name', () => {
    expect(formatGroupOption({ group_name: '6-B' })).toBe('6°B');
  });

  it('falls back to the raw value when object has no name fields', () => {
    expect(formatGroupOption('11A')).toBe('11°A');
  });

  it('handles empty object by coercing it to string', () => {
    // g.name || g.group_name || g => {} => String({}).trim() => '[object Object]'
    expect(formatGroupOption({})).toBe('[object Object]');
  });
});
