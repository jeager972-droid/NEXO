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
  getTeachers: async (workShift = '') => {
    const params = workShift ? { work_shift: workShift } : {};
    const response = await client.get('/school/teachers', { params });
    return response.data;
  },
  assignTeacher: async (groupId, teacherUserIds) => {
    const response = await client.post('/school/assign-teacher', { group_id: groupId, teacher_user_ids: teacherUserIds });
    return response.data;
  },
  unassignTeacher: async (groupId, teacherUserId) => {
    const response = await client.delete('/school/assign-teacher', { data: { group_id: groupId, teacher_user_id: teacherUserId } });
    return response.data;
  },
};
