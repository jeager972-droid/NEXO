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
};
