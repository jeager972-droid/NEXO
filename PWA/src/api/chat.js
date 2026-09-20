/**
 * chat API / NEXO Institucional
 * Cliente de «Pregúntale a Nexus» — chatbot intent-based con NLU.
 * Dependencias: axios client.js.
 */
import client from './client';

export const chatApi = {
  send: async (text) => {
    const { data } = await client.post('/chat/message', { text });
    if (data.status === 'error') throw new Error(data.message);
    return data.data; // {reply, intent, confidence, cards?, actions?, denied?}
  },
  history: async () => {
    const { data } = await client.get('/chat/history');
    return data?.data ?? [];
  },
  policies: async () => {
    const { data } = await client.get('/chat/policies');
    return data?.data ?? {};
  },
  savePolicies: async (policies) => {
    const { data } = await client.post('/chat/policies', { policies });
    if (data.status === 'error') throw new Error(data.message);
    return data.data;
  },
};
