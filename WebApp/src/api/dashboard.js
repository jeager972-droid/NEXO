import client from './client';

export const dashboardApi = {
  getStats: async (schoolId) => {
    const response = await client.get('/dashboard/stats', {
      params: { school_id: schoolId }
    });
    return response.data;
  },
};
