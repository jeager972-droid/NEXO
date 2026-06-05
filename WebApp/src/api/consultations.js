import client from './client';

export const consultationsApi = {
  queryModule: async (moduleName, groupName = '', fromDate = '', toDate = '', studentId = '') => {
    const payload = { module: moduleName };
    if (groupName) payload.group_name = groupName;
    if (fromDate) payload.from_date = fromDate;
    if (toDate) payload.to_date = toDate;
    if (studentId) payload.student_id = studentId;
    const response = await client.post('/consultations/query', payload);
    return response.data ?? { data: [], columns: {} };
  }
};
