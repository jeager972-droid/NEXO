/**
 * teacher API / NEXO Institucional
 * Responsabilidad: criterios de aviso del docente y onboarding docente.
 * Backend: routes/teacher_alerts.php — GET/POST/PUT/DELETE /teacher/alert-rules,
 * GET/POST /teacher/onboarding.
 */
import client from './client';

export const teacherApi = {
  getOnboarding: async () => {
    const response = await client.get('/teacher/onboarding');
    return response.data;
  },
  completeOnboarding: async ({ skipped = false } = {}) => {
    const response = await client.post('/teacher/onboarding', skipped ? { skipped: true } : { completed: true });
    return response.data;
  },
  getAlertRules: async () => {
    const response = await client.get('/teacher/alert-rules');
    return response.data;
  },
  createAlertRule: async (payload) => {
    const response = await client.post('/teacher/alert-rules', payload);
    return response.data;
  },
  updateAlertRule: async (ruleId, payload) => {
    const response = await client.put(`/teacher/alert-rules/${ruleId}`, payload);
    return response.data;
  },
  deleteAlertRule: async (ruleId) => {
    const response = await client.delete(`/teacher/alert-rules/${ruleId}`);
    return response.data;
  },
};
