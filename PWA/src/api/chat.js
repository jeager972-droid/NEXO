/**
 * chat API / NEXO Institucional
 * Cliente de «Pregúntale a Nexus» — chatbot intent-based con NLU.
 * Dependencias: axios client.js.
 */
import client from './client';

export const chatApi = {
  send: async (text, sessionId, ctx) => {
    const { data } = await client.post('/chat/message', { text, session_id: sessionId, ctx });
    if (data.status === 'error') throw new Error(data.message);
    return data.data; // {reply, intent, confidence, cards?, actions?, denied?}
  },
  history: async (sessionId) => {
    const { data } = await client.get('/chat/history', { params: sessionId ? { session_id: sessionId } : {} });
    return data?.data ?? [];
  },
  sessions: async () => {
    const { data } = await client.get('/chat/sessions');
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
