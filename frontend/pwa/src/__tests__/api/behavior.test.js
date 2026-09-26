import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../api/client', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import client from '../../api/client';
import { behaviorApi } from '../../api/behavior';

describe('behaviorApi', () => {
  beforeEach(() => vi.clearAllMocks());

  it('getRiskAnalysis calls GET /behavior/risk', async () => {
    client.get.mockResolvedValue({ data: { data: [{ student_id: 's1', risk: 'high' }] } });
    const result = await behaviorApi.getRiskAnalysis();
    expect(client.get).toHaveBeenCalledWith('/behavior/risk');
    expect(result.data).toHaveLength(1);
  });

  it('returns default { data: [] } when response is null', async () => {
    client.get.mockResolvedValue({ data: null });
    const result = await behaviorApi.getRiskAnalysis();
    expect(result).toEqual({ data: [] });
  });
});
