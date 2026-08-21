import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../api/client', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import client from '../../api/client';
import { trackingApi } from '../../api/tracking';

describe('trackingApi', () => {
  beforeEach(() => vi.clearAllMocks());

  it('startTracking calls POST /tracking/start with student_id', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    const result = await trackingApi.startTracking('s1');
    expect(client.post).toHaveBeenCalledWith('/tracking/start', { student_id: 's1' });
    expect(result.status).toBe('ok');
  });

  it('startTracking includes reason when provided', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await trackingApi.startTracking('s1', 'Absenteeism');
    expect(client.post).toHaveBeenCalledWith('/tracking/start', {
      student_id: 's1', reason: 'Absenteeism',
    });
  });

  it('startTracking omits reason when null', async () => {
    client.post.mockResolvedValue({ data: {} });
    await trackingApi.startTracking('s1', null);
    expect(client.post).toHaveBeenCalledWith('/tracking/start', { student_id: 's1' });
  });

  it('getActive calls GET /tracking/active', async () => {
    client.get.mockResolvedValue({ data: { data: [{ id: 1 }] } });
    const result = await trackingApi.getActive();
    expect(client.get).toHaveBeenCalledWith('/tracking/active');
    expect(result.data).toHaveLength(1);
  });

  it('addNote calls POST /tracking/notes with tracking_id, note_text, status', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await trackingApi.addNote('t1', 'Nota de seguimiento', 'resolved');
    expect(client.post).toHaveBeenCalledWith('/tracking/notes', {
      tracking_id: 't1', note_text: 'Nota de seguimiento', status: 'resolved',
    });
  });

  it('addNote passes null status when not provided', async () => {
    client.post.mockResolvedValue({ data: {} });
    await trackingApi.addNote('t1', 'Note');
    expect(client.post).toHaveBeenCalledWith('/tracking/notes', {
      tracking_id: 't1', note_text: 'Note', status: null,
    });
  });

  it('getDetails calls GET /tracking/details with tracking_id param', async () => {
    client.get.mockResolvedValue({ data: { id: 't1' } });
    const result = await trackingApi.getDetails('t1');
    expect(client.get).toHaveBeenCalledWith('/tracking/details', { params: { tracking_id: 't1' } });
    expect(result.id).toBe('t1');
  });
});
