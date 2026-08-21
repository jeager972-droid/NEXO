import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../api/client', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import client from '../../api/client';
import { studentsApi } from '../../api/students';

describe('studentsApi', () => {
  beforeEach(() => vi.clearAllMocks());

  describe('getAll', () => {
    it('calls GET /students with default params', async () => {
      client.get.mockResolvedValue({ data: { data: [], meta: { last_id: '0', has_more: false } } });
      const result = await studentsApi.getAll();
      expect(client.get).toHaveBeenCalledWith('/students', { params: { limit: 50 } });
      expect(result.students).toEqual([]);
      expect(result.hasMore).toBe(false);
    });

    it('includes last_id, search, group_name when provided', async () => {
      client.get.mockResolvedValue({ data: { data: [], meta: {} } });
      await studentsApi.getAll({ last_id: '123', search: 'juan', group_name: '6A', limit: 10 });
      expect(client.get).toHaveBeenCalledWith('/students', {
        params: { limit: 10, last_id: '123', search: 'juan', group_name: '6A' },
      });
    });

    it('omits last_id when 0 or "0"', async () => {
      client.get.mockResolvedValue({ data: { data: [] } });
      await studentsApi.getAll({ last_id: '0' });
      const params = client.get.mock.calls[0][1].params;
      expect(params.last_id).toBeUndefined();
    });

    it('normalizes student fields', async () => {
      client.get.mockResolvedValue({
        data: {
          data: [{
            student_id: 's1', first_name: 'Juan', last_name: 'Perez',
            group_name: '6A', fingerprint_id: 'fp1',
          }],
          meta: { last_id: 's1', has_more: true },
        },
      });
      const result = await studentsApi.getAll();
      expect(result.students[0].id).toBe('s1');
      expect(result.students[0].name).toBe('Perez Juan');
      expect(result.students[0].group).toBe('6A');
      expect(result.students[0].fingerprintId).toBe('fp1');
    });

    it('deduplicates by id', async () => {
      client.get.mockResolvedValue({
        data: {
          data: [
            { student_id: 's1', first_name: 'A', last_name: 'B' },
            { student_id: 's1', first_name: 'A', last_name: 'B' },
          ],
          meta: {},
        },
      });
      const result = await studentsApi.getAll();
      expect(result.students).toHaveLength(1);
    });

    it('defaults group to "Sin grupo" when missing', async () => {
      client.get.mockResolvedValue({
        data: { data: [{ student_id: 's1', first_name: 'A', last_name: 'B' }], meta: {} },
      });
      const result = await studentsApi.getAll();
      expect(result.students[0].group).toBe('Sin grupo');
    });
  });

  describe('getAllPaginated', () => {
    it('paginates until hasMore is false', async () => {
      client.get
        .mockResolvedValueOnce({
          data: { data: [{ student_id: 's1' }], meta: { last_id: 's1', has_more: true } },
        })
        .mockResolvedValueOnce({
          data: { data: [{ student_id: 's2' }], meta: { last_id: 's2', has_more: false } },
        });
      const result = await studentsApi.getAllPaginated();
      expect(result).toHaveLength(2);
      expect(client.get).toHaveBeenCalledTimes(2);
    });

    it('stops when batch is empty', async () => {
      client.get.mockResolvedValue({ data: { data: [], meta: { has_more: true } } });
      const result = await studentsApi.getAllPaginated();
      expect(result).toEqual([]);
    });
  });

  describe('getGroups', () => {
    it('calls GET /groups', async () => {
      client.get.mockResolvedValue({ data: { data: [{ name: '6A' }, { name: '6B' }] } });
      const result = await studentsApi.getGroups();
      expect(client.get).toHaveBeenCalledWith('/groups', { params: undefined });
      expect(result).toHaveLength(2);
    });

    it('passes teacher_only param when true', async () => {
      client.get.mockResolvedValue({ data: { data: [] } });
      await studentsApi.getGroups(true);
      expect(client.get).toHaveBeenCalledWith('/groups', { params: { teacher_only: '1' } });
    });

    it('deduplicates groups like 6A and 6-A', async () => {
      client.get.mockResolvedValue({
        data: { data: [{ name: '6A' }, { name: '6-A' }, { name: '6B' }] },
      });
      const result = await studentsApi.getGroups();
      expect(result).toHaveLength(2);
    });

    it('returns empty array when response is not array', async () => {
      client.get.mockResolvedValue({ data: { data: null } });
      const result = await studentsApi.getGroups();
      expect(result).toEqual([]);
    });
  });

  describe('create', () => {
    it('calls POST /students with data', async () => {
      client.post.mockResolvedValue({ data: { status: 'ok' } });
      const result = await studentsApi.create({ first_name: 'Test' });
      expect(client.post).toHaveBeenCalledWith('/students', { first_name: 'Test' });
      expect(result.status).toBe('ok');
    });
  });
});
