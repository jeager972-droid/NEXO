/**
 * school API / NEXO Institucional
 * Responsabilidad: Cliente para configuración de horarios institucionales y onboarding.
 * Dependencias: axios client.js.
 */
import client from './client';

export const schoolApi = {
  getConfig: async () => {
    const response = await client.get('/school/config');
    return response.data;
  },
  completeOnboarding: async (payload) => {
    const response = await client.post('/school/onboarding', payload);
    return response.data;
  },
  updateConfig: async (payload) => {
    const response = await client.put('/school/config', payload);
    return response.data;
  },
  updateTimeBlocks: async (timeBlocks) => {
    const response = await client.post('/school/time-blocks', { time_blocks: timeBlocks });
    return response.data;
  },
  getTimeBlocks: async () => {
    const response = await client.get('/school/time-blocks');
    return response.data;
  },
  getGroupsOnboarding: async () => {
    const response = await client.get('/school/groups-onboarding');
    return response.data;
  },
  completeGroupsOnboarding: async (payload) => {
    const response = await client.post('/school/groups-onboarding', payload);
    return response.data;
  },
  setSensorMasterKey: async (payload) => {
    const response = await client.post('/school/sensor-master-key', payload);
    return response.data;
  },
  groupsRollover: async () => {
    const response = await client.post('/school/groups-rollover', {});
    return response.data;
  },
};
