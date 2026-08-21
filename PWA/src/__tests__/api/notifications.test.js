import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../api/client', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import client from '../../api/client';
import { notificationsApi } from '../../api/notifications';

describe('notificationsApi', () => {
  beforeEach(() => vi.clearAllMocks());

  it('getAll calls GET /notifications and returns data array', async () => {
    client.get.mockResolvedValue({ data: { data: [{ id: 1 }] } });
    const result = await notificationsApi.getAll();
    expect(client.get).toHaveBeenCalledWith('/notifications');
    expect(result).toHaveLength(1);
  });

  it('getAll falls back to response.data when no nested data', async () => {
    client.get.mockResolvedValue({ data: [{ id: 1 }, { id: 2 }] });
    const result = await notificationsApi.getAll();
    expect(result).toHaveLength(2);
  });

  it('getAll returns empty array when response is null', async () => {
    client.get.mockResolvedValue({ data: null });
    const result = await notificationsApi.getAll();
    expect(result).toEqual([]);
  });

  it('create calls POST /notifications with data', async () => {
    client.post.mockResolvedValue({ data: { data: { id: 1 } } });
    const result = await notificationsApi.create({ title: 'Test' });
    expect(client.post).toHaveBeenCalledWith('/notifications', { title: 'Test' });
    expect(result.id).toBe(1);
  });

  it('create falls back to response.data when no nested data', async () => {
    client.post.mockResolvedValue({ data: { id: 2 } });
    const result = await notificationsApi.create({ title: 'Test' });
    expect(result.id).toBe(2);
  });

  it('clearAll calls POST /notifications/clear', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    const result = await notificationsApi.clearAll();
    expect(client.post).toHaveBeenCalledWith('/notifications/clear');
    expect(result.status).toBe('ok');
  });
});
