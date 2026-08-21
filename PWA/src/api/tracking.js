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
  }
};
