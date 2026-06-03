import client from './client';

export const behaviorApi = {
  getRiskAnalysis: async () => {
    const response = await client.get('/behavior/risk');
    return response.data ?? { data: [] };
  }
};
