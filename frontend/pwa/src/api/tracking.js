/**
 * tracking API / NEXO Institucional
 * Responsabilidad: Cliente para gestión de seguimiento estudiantil: iniciar, listar
 * activos, agregar notas y obtener detalles.
 * Dependencias: axios client.js.
 */
import apiClient from './client';

export const trackingApi = {
  startTracking: async (studentId, reason = null) => {
    const payload = { student_id: studentId };
    if (reason) {
      payload.reason = reason;
    }
    const response = await apiClient.post('/tracking/start', payload);
    return response.data;
  },

  getActive: async () => {
    const response = await apiClient.get('/tracking/active');
    return response.data;
  },

  addNote: async (trackingId, noteText, status = null) => {
    const response = await apiClient.post('/tracking/notes', { 
      tracking_id: trackingId, 
      note_text: noteText,
      status
    });
    return response.data;
  },

  getDetails: async (trackingId) => {
    const response = await apiClient.get('/tracking/details', { 
      params: { tracking_id: trackingId }
    });
    return response.data;
  },

  // Derivación manual: alerta o incidente → student_tracking
  derive: async ({ studentId, alertId, incidentId, dependency, assignedToUserId, reason }) => {
    const payload = { student_id: studentId, dependency };
    if (alertId) payload.alert_id = alertId;
    if (incidentId) payload.incident_id = incidentId;
    if (assignedToUserId) payload.assigned_to_user_id = assignedToUserId;
    if (reason) payload.reason = reason;
    const response = await apiClient.post('/tracking/derive', payload);
    return response.data;
  }
};
