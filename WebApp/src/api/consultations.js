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
  }
};
