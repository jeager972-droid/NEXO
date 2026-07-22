/**
 * consultations API / NEXO Institucional
 * Responsabilidad: Cliente de consulta general de módulos institucionales y búsqueda
 * de items individuales. Soporta cancelación por AbortSignal.
 * Dependencias: axios client.js.
 */
import client from './client';

export const consultationsApi = {
  queryModule: async (moduleName, groupName = '', fromDate = '', toDate = '', studentId = '', signal = null) => {
    const payload = { module: moduleName };
    if (groupName) payload.group_name = groupName;
    if (fromDate) payload.from_date = fromDate;
    if (toDate) payload.to_date = toDate;
    if (studentId) payload.student_id = studentId;
    const response = await client.post('/consultations/query', payload, { signal });
    const data = response.data ?? { data: [], columns: {} };
    if (data.status === 'error') {
      throw new Error(data.message || 'Error en el módulo de consulta');
    }
    return data;
  },

  // Busqueda directa (buscador de elementos individuales)
  search: async (query, module) => {
    const response = await client.get('/consultation/search', { params: { q: query, module } });
    return response.data?.data ?? response.data ?? [];
  },

  // Detalle de un item por ID
  getItemDetails: async (itemId, module) => {
    if (!itemId) return null;
    try {
      const response = await client.get('/consultation/details', { params: { id: itemId, module } });
      return response.data?.data ?? null;
    } catch (e) {
      console.error('getItemDetails error:', e);
      return null;
    }
  },
};
