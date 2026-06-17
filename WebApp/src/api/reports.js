import client from './client';

export const reportsApi = {
  getReport: async () => {
    const response = await client.get('/reports/preview');
    return response.data?.data ?? response.data ?? [];
  },
  // BUG-05 FIX: ahora acepta y pasa el rango de fechas al backend
  exportReport: async (startDate, endDate) => {
    const params = {};
    if (startDate) params.from = startDate;
    if (endDate) params.to = endDate;
    const response = await client.get('/reports/preview', { params });
    return response.data?.data ?? [];
  }
};
