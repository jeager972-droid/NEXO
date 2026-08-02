import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../api/client', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import client from '../../api/client';
import { dashboardApi } from '../../api/dashboard';

describe('dashboardApi', () => {
  beforeEach(() => vi.clearAllMocks());

  it('getStats calls GET /dashboard/stats with group_name', async () => {
    client.get.mockResolvedValue({ data: { total: 100 } });
    const result = await dashboardApi.getStats('6A');
    expect(client.get).toHaveBeenCalledWith('/dashboard/stats', { params: { group_name: '6A' } });
    expect(result.total).toBe(100);
  });

  it('getStats defaults to empty group_name', async () => {
    client.get.mockResolvedValue({ data: {} });
    await dashboardApi.getStats();
    expect(client.get).toHaveBeenCalledWith('/dashboard/stats', { params: { group_name: '' } });
  });

  it('getTeacherGroupDetail calls GET /dashboard/teacher-group-detail with all params', async () => {
    client.get.mockResolvedValue({ data: { rows: [] } });
    await dashboardApi.getTeacherGroupDetail('6A', 'attendance', '2024-01-01', '2024-12-31');
    expect(client.get).toHaveBeenCalledWith('/dashboard/teacher-group-detail', {
      params: { group_name: '6A', category: 'attendance', from_date: '2024-01-01', to_date: '2024-12-31' },
    });
  });

  it('getEvents calls GET /dashboard/events', async () => {
    client.get.mockResolvedValue({ data: [{ id: 1 }] });
    const result = await dashboardApi.getEvents();
    expect(client.get).toHaveBeenCalledWith('/dashboard/events');
    expect(result).toHaveLength(1);
  });
});
