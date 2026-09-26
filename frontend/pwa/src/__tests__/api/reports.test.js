import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../api/client', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import client from '../../api/client';
import { reportsApi } from '../../api/reports';

describe('reportsApi', () => {
  beforeEach(() => vi.clearAllMocks());

  it('getReport calls GET /reports/preview', async () => {
    client.get.mockResolvedValue({ data: { data: [{ id: 1 }] } });
    const result = await reportsApi.getReport();
    expect(client.get).toHaveBeenCalledWith('/reports/preview');
    expect(result).toHaveLength(1);
  });

  it('getReport falls back to response.data when no nested data', async () => {
    client.get.mockResolvedValue({ data: [{ id: 1 }, { id: 2 }] });
    const result = await reportsApi.getReport();
    expect(result).toHaveLength(2);
  });

  it('getReport returns empty array when response is null', async () => {
    client.get.mockResolvedValue({ data: null });
    const result = await reportsApi.getReport();
    expect(result).toEqual([]);
  });

  it('exportReport calls GET /reports/preview with date params', async () => {
    client.get.mockResolvedValue({ data: { data: [{ id: 1 }] } });
    const result = await reportsApi.exportReport('2024-01-01', '2024-12-31');
    expect(client.get).toHaveBeenCalledWith('/reports/preview', {
      params: { from: '2024-01-01', to: '2024-12-31' },
    });
    expect(result).toHaveLength(1);
  });

  it('exportReport omits empty date params', async () => {
    client.get.mockResolvedValue({ data: { data: [] } });
    await reportsApi.exportReport(null, null);
    expect(client.get).toHaveBeenCalledWith('/reports/preview', { params: {} });
  });

  it('exportReport passes only start date when end is empty', async () => {
    client.get.mockResolvedValue({ data: { data: [] } });
    await reportsApi.exportReport('2024-01-01', '');
    expect(client.get).toHaveBeenCalledWith('/reports/preview', {
      params: { from: '2024-01-01' },
    });
  });
});
