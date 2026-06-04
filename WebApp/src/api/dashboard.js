import client from './client';

export const dashboardApi = {
  getStats: async (schoolId) => {
    const response = await client.get('/dashboard/stats', {
      params: { school_id: schoolId }
    });
    return response.data;
  },
  getTeacherGroupDetail: async (groupName, category, fromDate, toDate) => {
    const response = await client.get('/dashboard/teacher-group-detail', {
      params: { group_name: groupName, category, from_date: fromDate, to_date: toDate }
    });
    return response.data;
  },
};
