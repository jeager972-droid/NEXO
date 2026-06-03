import client from './client';

export const consultationsApi = {
  queryModule: async (moduleName) => {
    const response = await client.post('/consultations/query', { module: moduleName });
    return response.data ?? { data: [], columns: {} };
  }
};
