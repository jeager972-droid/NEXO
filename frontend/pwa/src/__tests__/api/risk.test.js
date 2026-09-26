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
import { riskApi } from '@/api/risk';

describe('riskApi', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('getPolicy', () => {
    it('calls GET /risk/policy and returns data', async () => {
      client.get.mockResolvedValue({ data: { data: { policy: 'X' } } });
      const result = await riskApi.getPolicy();
      expect(client.get).toHaveBeenCalledWith('/risk/policy');
      expect(result).toEqual({ data: { policy: 'X' } });
    });

    it('returns default structure when no data', async () => {
      client.get.mockResolvedValue({ data: null });
      const result = await riskApi.getPolicy();
      expect(result).toEqual({ data: { policy: null, config: { rules: [], mapping: [], combos: [] } } });
    });
  });

  describe('getPolicyHistory', () => {
    it('calls GET /risk/policy/history and returns data', async () => {
      client.get.mockResolvedValue({ data: { data: [{ id: 1 }] } });
      const result = await riskApi.getPolicyHistory();
      expect(client.get).toHaveBeenCalledWith('/risk/policy/history');
      expect(result).toEqual({ data: [{ id: 1 }] });
    });

    it('returns default when no data', async () => {
      client.get.mockResolvedValue({ data: null });
      const result = await riskApi.getPolicyHistory();
      expect(result).toEqual({ data: [] });
    });
  });

  describe('createPolicyVersion', () => {
    it('calls POST /risk/policy with config and change_reason', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await riskApi.createPolicyVersion({ rules: [] }, 'initial');
      expect(client.post).toHaveBeenCalledWith('/risk/policy', { config: { rules: [] }, change_reason: 'initial' });
      expect(result).toEqual({ ok: true });
    });
  });

  describe('getEventTypes', () => {
    it('calls GET /risk/event-types and returns data', async () => {
      client.get.mockResolvedValue({ data: { data: [{ id: 'A' }] } });
      const result = await riskApi.getEventTypes();
      expect(client.get).toHaveBeenCalledWith('/risk/event-types');
      expect(result).toEqual({ data: [{ id: 'A' }] });
    });

    it('returns default when no data', async () => {
      client.get.mockResolvedValue({ data: null });
      const result = await riskApi.getEventTypes();
      expect(result).toEqual({ data: [] });
    });
  });

  describe('getAlerts', () => {
    it('calls GET /risk/alerts with level param when provided', async () => {
      client.get.mockResolvedValue({ data: { data: [] } });
      await riskApi.getAlerts('CRITICAL');
      expect(client.get).toHaveBeenCalledWith('/risk/alerts', { params: { level: 'CRITICAL' } });
    });

    it('calls GET /risk/alerts with empty params when no level', async () => {
      client.get.mockResolvedValue({ data: { data: [] } });
      await riskApi.getAlerts();
      expect(client.get).toHaveBeenCalledWith('/risk/alerts', { params: {} });
    });

    it('returns default when no data', async () => {
      client.get.mockResolvedValue({ data: null });
      const result = await riskApi.getAlerts();
      expect(result).toEqual({ data: [] });
    });
  });

  describe('resolveAlert', () => {
    it('calls POST /risk/alerts/:id/resolve with resolution_notes', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await riskApi.resolveAlert(42, 'resolved');
      expect(client.post).toHaveBeenCalledWith('/risk/alerts/42/resolve', { resolution_notes: 'resolved' });
      expect(result).toEqual({ ok: true });
    });
  });

  describe('escalateAlert', () => {
    it('calls POST /risk/alerts/:id/escalate with escalation_state and reason', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await riskApi.escalateAlert(7, 'ESCALATED', 'needs attention');
      expect(client.post).toHaveBeenCalledWith('/risk/alerts/7/escalate', { escalation_state: 'ESCALATED', reason: 'needs attention' });
      expect(result).toEqual({ ok: true });
    });
  });

  describe('getStudentRisk', () => {
    it('calls GET /risk/student/:id and returns data', async () => {
      client.get.mockResolvedValue({ data: { data: { score: 80 } } });
      const result = await riskApi.getStudentRisk('stu1');
      expect(client.get).toHaveBeenCalledWith('/risk/student/stu1');
      expect(result).toEqual({ data: { score: 80 } });
    });

    it('returns default empty object when no data', async () => {
      client.get.mockResolvedValue({ data: null });
      const result = await riskApi.getStudentRisk('stu1');
      expect(result).toEqual({ data: {} });
    });
  });

  describe('justifyEvent', () => {
    it('calls POST /risk/justify with payload', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await riskApi.justifyEvent({ event_id: 1, reason: 'sick' });
      expect(client.post).toHaveBeenCalledWith('/risk/justify', { event_id: 1, reason: 'sick' });
      expect(result).toEqual({ ok: true });
    });
  });

  describe('recalculate', () => {
    it('calls POST /risk/recalculate', async () => {
      client.post.mockResolvedValue({ data: { ok: true } });
      const result = await riskApi.recalculate();
      expect(client.post).toHaveBeenCalledWith('/risk/recalculate');
      expect(result).toEqual({ ok: true });
    });
  });

  describe('getAnomaly', () => {
    it('calls GET /risk/anomaly/:id with category param (default asistencia)', async () => {
      client.get.mockResolvedValue({ data: { data: { z: 3 } } });
      const result = await riskApi.getAnomaly('stu1');
      expect(client.get).toHaveBeenCalledWith('/risk/anomaly/stu1', { params: { category: 'asistencia' } });
      expect(result).toEqual({ data: { z: 3 } });
    });

    it('accepts a custom category', async () => {
      client.get.mockResolvedValue({ data: { data: {} } });
      await riskApi.getAnomaly('stu1', 'rendimiento');
      expect(client.get).toHaveBeenCalledWith('/risk/anomaly/stu1', { params: { category: 'rendimiento' } });
    });

    it('returns default empty object when no data', async () => {
      client.get.mockResolvedValue({ data: null });
      const result = await riskApi.getAnomaly('stu1');
      expect(result).toEqual({ data: {} });
    });
  });
});
