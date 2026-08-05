/**
 * notifications API / NEXO Institucional
 * Responsabilidad: Cliente para notificaciones del usuario: listar, crear y marcar
 * todas como leídas.
 * Dependencias: axios client.js.
 */
import client from './client';

export const notificationsApi = {
  getAll: async () => {
    const response = await client.get('/notifications');
    return response.data?.data ?? response.data ?? [];
  },
  create: async (data) => {
    const response = await client.post('/notifications', data);
    return response.data?.data ?? response.data;
  },
  clearAll: async () => {
    const response = await client.post('/notifications/clear');
    return response.data;
  },
  executeAction: async (notificationId, action) => {
    const response = await client.post(`/notifications/${notificationId}/action`, { action });
    return response.data;
  },
};
