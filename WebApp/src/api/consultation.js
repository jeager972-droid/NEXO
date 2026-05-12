import client from './client';

export const consultationApi = {
  search: async (query, module) => {
    const response = await client.get('/consultation/search', {
      params: { q: query, module }
    });
    return response.data?.data ?? response.data ?? [];
  },
  getItemDetails: async () => null
};
