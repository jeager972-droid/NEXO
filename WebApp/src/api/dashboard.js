import client from './client';

export const dashboardApi = {
  getStats: async (groupName = '') => {
    const response = await client.get('/dashboard/stats', {
      params: { group_name: groupName }
    });
    return response.data;
  },
  getTeacherGroupDetail: async (groupName, category, fromDate, toDate) => {
    const response = await client.get('/dashboard/teacher-group-detail', {
      params: { group_name: groupName, category, from_date: fromDate, to_date: toDate }
    });
    return response.data;
  },
  getEvents: async () => {
    const response = await client.get('/dashboard/events');
    return response.data;
  },
};
