/**
 * risk API / NEXO Institucional
 * Responsabilidad: Cliente para el Motor de Análisis de Riesgo Pedagógico v3.0.
 * Dependencias: axios client.js.
 */

import client from './client';

export const riskApi = {
  // ── Política institucional ──────────────────────────────────────────
  getPolicy: async () => {
    const res = await client.get('/risk/policy');
    return res.data ?? { data: { policy: null, config: { rules: [], mapping: [], combos: [] } } };
  },

  getPolicyHistory: async () => {
    const res = await client.get('/risk/policy/history');
    return res.data ?? { data: [] };
  },

  createPolicyVersion: async (config, changeReason) => {
    const res = await client.post('/risk/policy', { config, change_reason: changeReason });
    return res.data;
  },

  // ── Tipos de evento ─────────────────────────────────────────────────
  getEventTypes: async () => {
    const res = await client.get('/risk/event-types');
    return res.data ?? { data: [] };
  },

  // ── Alertas ─────────────────────────────────────────────────────────
  getAlerts: async (level = null) => {
    const params = level ? { level } : {};
    const res = await client.get('/risk/alerts', { params });
    return res.data ?? { data: [] };
  },

  resolveAlert: async (alertId, resolutionNotes) => {
    const res = await client.post(`/risk/alerts/${alertId}/resolve`, {
      resolution_notes: resolutionNotes,
    });
    return res.data;
  },

  escalateAlert: async (alertId, escalationState, reason) => {
    const res = await client.post(`/risk/alerts/${alertId}/escalate`, {
      escalation_state: escalationState,
      reason,
    });
    return res.data;
  },

  // ── Perfil de estudiante ────────────────────────────────────────────
  getStudentRisk: async (studentId) => {
    const res = await client.get(`/risk/student/${studentId}`);
    return res.data ?? { data: {} };
  },

  // ── Justificaciones ─────────────────────────────────────────────────
  justifyEvent: async (payload) => {
    const res = await client.post('/risk/justify', payload);
    return res.data;
  },

  // ── Recálculo ───────────────────────────────────────────────────────
  recalculate: async () => {
    const res = await client.post('/risk/recalculate');
    return res.data;
  },

  // ── Anomalía estadística (Capa 8) ───────────────────────────────────
  getAnomaly: async (studentId, category = 'asistencia') => {
    const res = await client.get(`/risk/anomaly/${studentId}`, { params: { category } });
    return res.data ?? { data: {} };
  },
};
