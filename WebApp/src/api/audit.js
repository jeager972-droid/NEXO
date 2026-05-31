import client from './client';

export const auditApi = {
  getGlobalLogs: async () => {
    const response = await client.get('/audit/global');
    return response.data?.data ?? response.data ?? [];
  },
  getIntegrity: async () => {
    const response = await client.get('/audit/integrity');
    return response.data ?? {};
  },
};
