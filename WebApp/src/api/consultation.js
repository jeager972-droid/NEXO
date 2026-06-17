import client from './client';

export const consultationApi = {
  search: async (query, module) => {
    const response = await client.get('/consultation/search', {
      params: { q: query, module }
    });
    return response.data?.data ?? response.data ?? [];
  },
  // BUG-16 FIX: ya no es un stub — hace fetch real al backend
  // Retorna null de forma segura si no hay id o si la petición falla
  getItemDetails: async (itemId, module) => {
    if (!itemId) return null;
    try {
      const response = await client.get('/consultation/details', {
        params: { id: itemId, module }
      });
      return response.data?.data ?? null;
    } catch (e) {
      console.error('getItemDetails error:', e);
      return null;
    }
  }
};
