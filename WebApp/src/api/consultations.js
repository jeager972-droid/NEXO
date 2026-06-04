import client from './client';

export const consultationsApi = {
  queryModule: async (moduleName, groupName = '', fromDate = '', toDate = '') => {
    const payload = { module: moduleName };
    if (groupName) payload.group_name = groupName;
    if (fromDate) payload.from_date = fromDate;
    if (toDate) payload.to_date = toDate;
    const response = await client.post('/consultations/query', payload);
    return response.data ?? { data: [], columns: {} };
  }
};
