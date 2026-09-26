import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@/api/client', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));

import client from '@/api/client';
import { schoolApi } from '@/api/school';

describe('schoolApi', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('getConfig', () => {
    it('calls GET /school/config and returns data', async () => {
      client.get.mockResolvedValue({ data: { id: 1 } });
      const result = await schoolApi.getConfig();
      expect(client.get).toHaveBeenCalledWith('/school/config');
      expect(result).toEqual({ id: 1 });
    });
  });

  describe('completeOnboarding', () => {
    it('calls POST /school/onboarding with payload', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await schoolApi.completeOnboarding({ name: 'School' });
      expect(client.post).toHaveBeenCalledWith('/school/onboarding', { name: 'School' });
      expect(result).toEqual({ ok: true });
    });
  });

  describe('updateConfig', () => {
    it('calls PUT /school/config with payload', async () => {
      client.put.mockResolvedValue({ data: { ok: true } });
      const result = await schoolApi.updateConfig({ timezone: 'UTC' });
      expect(client.put).toHaveBeenCalledWith('/school/config', { timezone: 'UTC' });
      expect(result).toEqual({ ok: true });
    });
  });

  describe('updateTimeBlocks', () => {
    it('calls POST /school/time-blocks with time_blocks array', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const blocks = [{ start: '08:00', end: '09:00' }];
      const result = await schoolApi.updateTimeBlocks(blocks);
      expect(client.post).toHaveBeenCalledWith('/school/time-blocks', { time_blocks: blocks });
      expect(result).toEqual({ ok: true });
    });
  });

  describe('getTimeBlocks', () => {
    it('calls GET /school/time-blocks and returns data', async () => {
      client.get.mockResolvedValue({ data: [{ id: 1 }] });
      const result = await schoolApi.getTimeBlocks();
      expect(client.get).toHaveBeenCalledWith('/school/time-blocks');
      expect(result).toEqual([{ id: 1 }]);
    });
  });

  describe('getGroupsOnboarding', () => {
    it('calls GET /school/groups-onboarding and returns data', async () => {
      client.get.mockResolvedValue({ data: { groups: [] } });
      const result = await schoolApi.getGroupsOnboarding();
      expect(client.get).toHaveBeenCalledWith('/school/groups-onboarding');
      expect(result).toEqual({ groups: [] });
    });
  });

  describe('completeGroupsOnboarding', () => {
    it('calls POST /school/groups-onboarding with payload', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await schoolApi.completeGroupsOnboarding({ groups: [{ name: 'A' }] });
      expect(client.post).toHaveBeenCalledWith('/school/groups-onboarding', { groups: [{ name: 'A' }] });
      expect(result).toEqual({ ok: true });
    });
  });

  describe('setSensorMasterKey', () => {
    it('calls POST /school/sensor-master-key with payload', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await schoolApi.setSensorMasterKey({ master_key: 'mk' });
      expect(client.post).toHaveBeenCalledWith('/school/sensor-master-key', { master_key: 'mk' });
      expect(result).toEqual({ ok: true });
    });
  });

  describe('getTeachers', () => {
    it('calls GET /school/teachers with work_shift param when provided', async () => {
      client.get.mockResolvedValue({ data: [{ id: 1 }] });
      const result = await schoolApi.getTeachers('morning');
      expect(client.get).toHaveBeenCalledWith('/school/teachers', { params: { work_shift: 'morning' } });
      expect(result).toEqual([{ id: 1 }]);
    });

    it('calls GET /school/teachers with empty params when no work shift', async () => {
      client.get.mockResolvedValue({ data: [] });
      await schoolApi.getTeachers();
      expect(client.get).toHaveBeenCalledWith('/school/teachers', { params: {} });
    });

    it('calls GET /school/teachers with empty params when empty string', async () => {
      client.get.mockResolvedValue({ data: [] });
      await schoolApi.getTeachers('');
      expect(client.get).toHaveBeenCalledWith('/school/teachers', { params: {} });
    });
  });

  describe('assignTeacher', () => {
    it('calls POST /school/assign-teacher with group_id and teacher_user_ids', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await schoolApi.assignTeacher(5, [1, 2, 3]);
      expect(client.post).toHaveBeenCalledWith('/school/assign-teacher', { group_id: 5, teacher_user_ids: [1, 2, 3] });
      expect(result).toEqual({ ok: true });
    });
  });

  describe('unassignTeacher', () => {
    it('calls DELETE /school/assign-teacher with data body containing group_id and teacher_user_id', async () => {
      client.delete.mockResolvedValue({ data: { ok: true } });
      const result = await schoolApi.unassignTeacher(5, 9);
      expect(client.delete).toHaveBeenCalledWith('/school/assign-teacher', { data: { group_id: 5, teacher_user_id: 9 } });
      expect(result).toEqual({ ok: true });
    });
  });
});
