import { describe, it, expect } from 'vitest';
import {
  fmtShortDateOnly,
  fmt12h,
  humanizeColumn,
  formatCellValue,
  ENUM_LABELS,
  COLUMN_LABELS,
  EXCLUDE_COLS,
} from '@/utils/formatters';

describe('fmtShortDateOnly', () => {
  it('formats a valid ISO date as "D Mon YYYY"', () => {
    expect(fmtShortDateOnly('2024-03-05T10:00:00')).toBe('5 Mar 2024');
  });

  it('returns the input unchanged for invalid dates', () => {
    expect(fmtShortDateOnly('not-a-date')).toBe('not-a-date');
  });
});

describe('fmt12h', () => {
  it('returns "—" for falsy input', () => {
    expect(fmt12h('')).toBe('—');
    expect(fmt12h(null)).toBe('—');
    expect(fmt12h(undefined)).toBe('—');
  });

  it('formats date-only strings (YYYY-MM-DD) as "D Mon YYYY"', () => {
    expect(fmt12h('2024-03-05')).toBe('5 Mar 2024');
  });

  it('returns string representation for invalid dates', () => {
    expect(fmt12h('not-a-date')).toBe('not-a-date');
  });

  it('formats full ISO datetime using toLocaleString', () => {
    const result = fmt12h('2024-03-05T14:30:00');
    expect(result).toContain('2024');
    expect(result).toMatch(/mar|Mar/);
  });
});

describe('ENUM_LABELS', () => {
  it('contains expected mappings', () => {
    expect(ENUM_LABELS.CHECK_IN).toBe('Entrada');
    expect(ENUM_LABELS.SUCCESS).toBe('Exitoso');
    expect(ENUM_LABELS.CRITICAL).toBe('Crítico');
  });
});

describe('COLUMN_LABELS', () => {
  it('contains expected mappings', () => {
    expect(COLUMN_LABELS.first_name).toBe('Nombre');
    expect(COLUMN_LABELS.status).toBe('Estado');
  });
});

describe('EXCLUDE_COLS', () => {
  it('is an array containing technical id columns', () => {
    expect(Array.isArray(EXCLUDE_COLS)).toBe(true);
    expect(EXCLUDE_COLS).toContain('student_id');
    expect(EXCLUDE_COLS).toContain('metadata_json');
  });
});

describe('humanizeColumn', () => {
  it('returns the label from COLUMN_LABELS when present', () => {
    expect(humanizeColumn('first_name')).toBe('Nombre');
  });

  it('replaces underscores with spaces and capitalizes first letter', () => {
    expect(humanizeColumn('some_custom_key')).toBe('Some custom key');
  });
});

describe('formatCellValue', () => {
  it('returns "—" for null and undefined', () => {
    expect(formatCellValue('x', null)).toBe('—');
    expect(formatCellValue('x', undefined)).toBe('—');
  });

  it('returns "Sí"/"No" for booleans', () => {
    expect(formatCellValue('x', true)).toBe('Sí');
    expect(formatCellValue('x', false)).toBe('No');
  });

  it('formats timestamp-like keys via fmt12h', () => {
    const result = formatCellValue('event_timestamp', '2024-03-05T14:30:00');
    expect(result).toContain('2024');
  });

  it('formats date-like keys via fmtShortDateOnly', () => {
    // Use a datetime with time to avoid UTC-midnight timezone shifts
    expect(formatCellValue('birth_date', '2024-03-05T10:00:00')).toBe('5 Mar 2024');
  });

  it('returns a span for "en proceso"', () => {
    const el = formatCellValue('status', 'en proceso');
    expect(el.type).toBe('span');
    expect(el.props.children).toBe('En Proceso');
  });

  it('returns a span for "resuelto"', () => {
    const el = formatCellValue('status', 'resuelto');
    expect(el.type).toBe('span');
    expect(el.props.children).toBe('Resuelto');
  });

  it('returns ENUM_LABELS value for known enum strings', () => {
    expect(formatCellValue('event_type', 'CHECK_IN')).toBe('Entrada');
  });

  it('matches enum labels case-insensitively', () => {
    expect(formatCellValue('event_type', 'check_in')).toBe('Entrada');
  });

  it('truncates long strings to 120 chars with ellipsis', () => {
    const long = 'x'.repeat(200);
    const result = formatCellValue('message', long);
    expect(result.length).toBe(121); // 120 + ellipsis char
    expect(result.endsWith('…')).toBe(true);
  });

  it('returns the trimmed string for plain values', () => {
    expect(formatCellValue('name', '  John  ')).toBe('John');
  });
});
