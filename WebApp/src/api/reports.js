import client from './client';

export const reportsApi = {
  getReport: async () => {
    const response = await client.get('/reports/preview');
    return response.data?.data ?? response.data ?? [];
  },
  exportReport: async () => {
    const response = await client.get('/reports/preview');
    return response.data?.data ?? [];
  }
};
