import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../api/client', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import client from '../../api/client';
import { consultationsApi } from '../../api/consultations';

describe('consultationsApi', () => {
  beforeEach(() => vi.clearAllMocks());

  it('queryModule calls POST /consultations/query with module and optional params', async () => {
    client.post.mockResolvedValue({ data: { data: [], columns: {} } });
    await consultationsApi.queryModule('attendance', '6A', '2024-01-01', '2024-12-31', 's1');
    expect(client.post).toHaveBeenCalledWith('/consultations/query', {
      module: 'attendance',
      group_name: '6A',
      from_date: '2024-01-01',
      to_date: '2024-12-31',
      student_id: 's1',
    }, { signal: null });
  });

  it('queryModule omits empty optional params', async () => {
    client.post.mockResolvedValue({ data: { data: [] } });
    await consultationsApi.queryModule('discipline');
    expect(client.post).toHaveBeenCalledWith('/consultations/query', {
      module: 'discipline',
    }, { signal: null });
  });

  it('queryModule passes AbortSignal', async () => {
    client.post.mockResolvedValue({ data: { data: [] } });
    const controller = new AbortController();
    await consultationsApi.queryModule('test', '', '', '', '', controller.signal);
    expect(client.post).toHaveBeenCalledWith('/consultations/query',
      { module: 'test' },
      { signal: controller.signal },
    );
  });

  it('queryModule throws when status is error', async () => {
    client.post.mockResolvedValue({ data: { status: 'error', message: 'Módulo no disponible' } });
    await expect(consultationsApi.queryModule('bad')).rejects.toThrow('Módulo no disponible');
  });

  it('queryModule returns default structure when data is null', async () => {
    client.post.mockResolvedValue({ data: null });
    const result = await consultationsApi.queryModule('test');
    expect(result).toEqual({ data: [], columns: {} });
  });

  it('search calls GET /consultation/search with q and module', async () => {
    client.get.mockResolvedValue({ data: { data: [{ id: 1 }] } });
    const result = await consultationsApi.search('juan', 'students');
    expect(client.get).toHaveBeenCalledWith('/consultation/search', {
      params: { q: 'juan', module: 'students' },
    });
    expect(result).toHaveLength(1);
  });

  it('search falls back to response.data when no nested data', async () => {
    client.get.mockResolvedValue({ data: [{ id: 1 }] });
    const result = await consultationsApi.search('test', 'module');
    expect(result).toHaveLength(1);
  });

  it('search returns empty array when response is null', async () => {
    client.get.mockResolvedValue({ data: null });
    const result = await consultationsApi.search('test', 'module');
    expect(result).toEqual([]);
  });
});
