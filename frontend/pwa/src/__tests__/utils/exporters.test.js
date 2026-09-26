import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
  humanizeKey,
  visibleColumns,
  exportCsv,
  exportExcel,
  exportWord,
  exportPdf,
  EXPORT_FORMATS,
} from '@/utils/exporters';

describe('humanizeKey', () => {
  it('returns override label when present', () => {
    expect(humanizeKey('student_id')).toBe('ID estudiante');
    expect(humanizeKey('group_name')).toBe('Grupo');
  });

  it('title-cases and replaces underscores for unknown keys', () => {
    expect(humanizeKey('some_field')).toBe('Some Field');
  });
});

describe('visibleColumns', () => {
  it('returns empty array for non-array input', () => {
    expect(visibleColumns(null)).toEqual([]);
    expect(visibleColumns('nope')).toEqual([]);
  });

  it('returns empty array for empty array', () => {
    expect(visibleColumns([])).toEqual([]);
  });

  it('collects keys from rows', () => {
    const rows = [{ a: 1, b: 2 }, { c: 3 }];
    expect(visibleColumns(rows).sort()).toEqual(['a', 'b', 'c']);
  });

  it('excludes PII keys', () => {
    const rows = [{ name: 'x', password: 'y', token: 'z' }];
    expect(visibleColumns(rows)).toEqual(['name']);
  });

  it('excludes hidden keys', () => {
    const rows = [{ name: 'x', metadata: {}, raw: 'r', __typename: 'T' }];
    expect(visibleColumns(rows)).toEqual(['name']);
  });

  it('handles rows with null entries', () => {
    const rows = [null, { a: 1 }];
    expect(visibleColumns(rows)).toEqual(['a']);
  });
});

describe('exportCsv', () => {
  beforeEach(() => {
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:url');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
    vi.spyOn(document.body, 'appendChild').mockImplementation(() => {});
    vi.spyOn(document.body, 'removeChild').mockImplementation(() => {});
  });

  it('creates a blob and triggers a download', () => {
    const rows = [{ name: 'Ana', age: 20 }];
    const linkClick = vi.fn();
    const createEl = vi.spyOn(document, 'createElement').mockReturnValue({
      href: '',
      download: '',
      rel: '',
      click: linkClick,
    });

    exportCsv({ title: 'Reporte', rows });

    expect(URL.createObjectURL).toHaveBeenCalled();
    expect(linkClick).toHaveBeenCalled();
    expect(createEl.mock.calls[0][0]).toBe('a');

    createEl.mockRestore();
  });

  it('uses provided columns when given', () => {
    vi.spyOn(document, 'createElement').mockReturnValue({
      href: '', download: '', rel: '', click: vi.fn(),
    });
    const rows = [{ a: 1, b: 2 }];
    exportCsv({ title: 'T', rows, columns: ['a'] });
    // Just verify it doesn't throw; download triggered
    expect(URL.createObjectURL).toHaveBeenCalled();
  });
});

describe('exportExcel', () => {
  beforeEach(() => {
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:url');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
    vi.spyOn(document.body, 'appendChild').mockImplementation(() => {});
    vi.spyOn(document.body, 'removeChild').mockImplementation(() => {});
    vi.spyOn(document, 'createElement').mockReturnValue({
      href: '', download: '', rel: '', click: vi.fn(),
    });
  });

  it('generates an Excel blob and downloads .xls', () => {
    exportExcel({ title: 'Reporte', rows: [{ a: 1 }] });
    expect(URL.createObjectURL).toHaveBeenCalled();
  });
});

describe('exportWord', () => {
  beforeEach(() => {
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:url');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
    vi.spyOn(document.body, 'appendChild').mockImplementation(() => {});
    vi.spyOn(document.body, 'removeChild').mockImplementation(() => {});
    vi.spyOn(document, 'createElement').mockReturnValue({
      href: '', download: '', rel: '', click: vi.fn(),
    });
  });

  it('generates a Word blob and downloads .doc', () => {
    exportWord({ title: 'Reporte', rows: [{ a: 1 }] });
    expect(URL.createObjectURL).toHaveBeenCalled();
  });
});

describe('exportPdf', () => {
  it('opens a new window and writes HTML', () => {
    const fakeWin = {
      document: { write: vi.fn(), close: vi.fn() },
      focus: vi.fn(),
      print: vi.fn(),
    };
    vi.spyOn(window, 'open').mockReturnValue(fakeWin);
    exportPdf({ title: 'Reporte', rows: [{ a: 1 }] });
    expect(fakeWin.document.write).toHaveBeenCalled();
    expect(fakeWin.document.close).toHaveBeenCalled();
    expect(fakeWin.focus).toHaveBeenCalled();
  });

  it('does nothing when window.open returns null (popup blocked)', () => {
    vi.spyOn(window, 'open').mockReturnValue(null);
    expect(() => exportPdf({ title: 'R', rows: [] })).not.toThrow();
  });
});

describe('EXPORT_FORMATS', () => {
  it('contains excel, word and pdf entries', () => {
    const ids = EXPORT_FORMATS.map((f) => f.id);
    expect(ids).toEqual(['excel', 'word', 'pdf']);
  });

  it('each entry has run, label and extension', () => {
    EXPORT_FORMATS.forEach((f) => {
      expect(typeof f.run).toBe('function');
      expect(typeof f.label).toBe('string');
      expect(typeof f.extension).toBe('string');
    });
  });
});
