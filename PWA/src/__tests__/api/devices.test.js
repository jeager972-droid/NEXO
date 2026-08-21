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
import { devicesApi } from '@/api/devices';

describe('devicesApi', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('getAll', () => {
    it('calls GET /devices and returns data array', async () => {
      client.get.mockResolvedValue({ data: { data: [{ id: 1 }, { id: 2 }] } });
      const result = await devicesApi.getAll();
      expect(client.get).toHaveBeenCalledWith('/devices');
      expect(result).toEqual([{ id: 1 }, { id: 2 }]);
    });

    it('returns empty array when no data', async () => {
      client.get.mockResolvedValue({ data: {} });
      const result = await devicesApi.getAll();
      expect(result).toEqual([]);
    });
  });

  describe('getByRole', () => {
    it('calls GET /devices/by-role and returns data', async () => {
      client.get.mockResolvedValue({ data: { data: { ADMIN: [] } } });
      const result = await devicesApi.getByRole();
      expect(client.get).toHaveBeenCalledWith('/devices/by-role');
      expect(result).toEqual({ ADMIN: [] });
    });

    it('returns null when no data', async () => {
      client.get.mockResolvedValue({ data: {} });
      const result = await devicesApi.getByRole();
      expect(result).toBeNull();
    });
  });

  describe('sendCommand', () => {
    it('calls POST /devices/command/:id with command and payload', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await devicesApi.sendCommand('dev1', 'PING', { foo: 'bar' });
      expect(client.post).toHaveBeenCalledWith('/devices/command/dev1', { command: 'PING', payload: { foo: 'bar' } });
      expect(result).toEqual({ ok: true });
    });

    it('defaults payload to empty object', async () => {
      client.post.mockResolvedValue({ data: {} });
      await devicesApi.sendCommand('dev1', 'PING');
      expect(client.post).toHaveBeenCalledWith('/devices/command/dev1', { command: 'PING', payload: {} });
    });
  });

  describe('requestEnrollment', () => {
    it('delegates to sendCommand with ENROLL_REQUEST', async () => {
      client.post.mockResolvedValue({ data: {} });
      await devicesApi.requestEnrollment('dev1', { doc: '123', nombre: 'Juan', tel: '555' });
      expect(client.post).toHaveBeenCalledWith('/devices/command/dev1', {
        command: 'ENROLL_REQUEST',
        payload: { doc: '123', nombre: 'Juan', tel: '555' },
      });
    });
  });

  describe('authorizeExit', () => {
    it('delegates to sendCommand with AUTHORIZE_EXIT', async () => {
      client.post.mockResolvedValue({ data: {} });
      await devicesApi.authorizeExit('dev1', '12345678');
      expect(client.post).toHaveBeenCalledWith('/devices/command/dev1', {
        command: 'AUTHORIZE_EXIT',
        payload: { doc: '12345678' },
      });
    });
  });

  describe('register', () => {
    it('calls POST /devices with registration fields', async () => {
      client.post.mockResolvedValue({ data: { data: { id: 9 } } });
      const result = await devicesApi.register({ name: 'Sensor A', location: 'Lab', group_id: 1, assigned_user_id: 5 });
      expect(client.post).toHaveBeenCalledWith('/devices', { name: 'Sensor A', location: 'Lab', group_id: 1, assigned_user_id: 5 });
      expect(result).toEqual({ id: 9 });
    });

    it('returns null when no data', async () => {
      client.post.mockResolvedValue({ data: {} });
      const result = await devicesApi.register({ name: 'X' });
      expect(result).toBeNull();
    });
  });

  describe('configure', () => {
    it('calls POST /devices/:id/configure with empty body', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await devicesApi.configure('dev1');
      expect(client.post).toHaveBeenCalledWith('/devices/dev1/configure', {});
      expect(result).toEqual({ ok: true });
    });
  });

  describe('revoke', () => {
    it('calls DELETE /devices/:id', async () => {
      client.delete.mockResolvedValue({ data: { ok: true } });
      const result = await devicesApi.revoke('dev1');
      expect(client.delete).toHaveBeenCalledWith('/devices/dev1');
      expect(result).toEqual({ ok: true });
    });
  });

  describe('startRevocation', () => {
    it('calls POST /devices/:id/revocation with password', async () => {
      client.post.mockResolvedValue({ data: { id: 'rev1' } });
      const result = await devicesApi.startRevocation('dev1', 'secret');
      expect(client.post).toHaveBeenCalledWith('/devices/dev1/revocation', { password: 'secret' });
      expect(result).toEqual({ id: 'rev1' });
    });
  });

  describe('cancelRevocation', () => {
    it('calls POST /devices/:id/revocation/cancel with revocation_id and password', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await devicesApi.cancelRevocation('dev1', 'rev1', 'secret');
      expect(client.post).toHaveBeenCalledWith('/devices/dev1/revocation/cancel', { revocation_id: 'rev1', password: 'secret' });
      expect(result).toEqual({ ok: true });
    });
  });

  describe('getPendingRevocations', () => {
    it('calls GET /devices/revocations/pending and returns data array', async () => {
      client.get.mockResolvedValue({ data: { data: [{ id: 'rev1' }] } });
      const result = await devicesApi.getPendingRevocations();
      expect(client.get).toHaveBeenCalledWith('/devices/revocations/pending');
      expect(result).toEqual([{ id: 'rev1' }]);
    });

    it('returns empty array when no data', async () => {
      client.get.mockResolvedValue({ data: {} });
      const result = await devicesApi.getPendingRevocations();
      expect(result).toEqual([]);
    });
  });

  describe('reconfigure', () => {
    it('calls POST /devices/:id/reconfigure with master_key, device_id and token', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await devicesApi.reconfigure('dev1', 'tok', 'mk');
      expect(client.post).toHaveBeenCalledWith('/devices/dev1/reconfigure', { master_key: 'mk', device_id: 'dev1', token: 'tok' });
      expect(result).toEqual({ ok: true });
    });
  });
});
