import apiClient from './client';

export const trackingApi = {
  startTracking: async (studentId) => {
    const response = await apiClient.post('/tracking/start', { student_id: studentId });
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
