/**
 * devices API / NEXO Institucional
 * Responsabilidad: Cliente para dispositivos edge biométricos: listado, registro,
 * configuración, revocación (con countdown de 1h), reconfiguración con master key,
 * y envío de comandos M2M (ENROLL_REQUEST, AUTHORIZE_EXIT, etc).
 * El comando viaja API -> MQTT (fallback Redis) -> nexo-edge.
 * Dependencias: axios client.js.
 */
import client from './client';

export const devicesApi = {
  getAll: async () => {
    const response = await client.get('/devices');
    return response.data?.data ?? [];
  },
  getByRole: async () => {
    const response = await client.get('/devices/by-role');
    return response.data?.data ?? null;
  },
  sendCommand: async (deviceId, command, payload = {}) => {
    const response = await client.post(`/devices/command/${deviceId}`, { command, payload });
    return response.data;
  },
  requestEnrollment: (deviceId, { doc, nombre, tel, finger_slot = 1 }) =>
    devicesApi.sendCommand(deviceId, 'ENROLL_REQUEST', { doc, nombre, tel, finger_slot }),
  authorizeExit: (deviceId, doc) =>
    devicesApi.sendCommand(deviceId, 'AUTHORIZE_EXIT', { doc }),
  register: async ({ name, location, group_id, assigned_user_id }) => {
    const response = await client.post('/devices', { name, location, group_id, assigned_user_id });
    return response.data?.data ?? null;
  },
  configure: async (deviceId) => {
    const response = await client.post(`/devices/${deviceId}/configure`, {});
    return response.data;
  },
  revoke: async (deviceId) => {
    const response = await client.delete(`/devices/${deviceId}`);
    return response.data;
  },
  startRevocation: async (deviceId, password) => {
    const response = await client.post(`/devices/${deviceId}/revocation`, { password });
    return response.data;
  },
  cancelRevocation: async (deviceId, revocationId, password) => {
    const response = await client.post(`/devices/${deviceId}/revocation/cancel`, { revocation_id: revocationId, password });
    return response.data;
  },
  getPendingRevocations: async () => {
    const response = await client.get('/devices/revocations/pending');
    return response.data?.data ?? [];
  },
  reconfigure: async (deviceId, token, masterKey) => {
    const response = await client.post(`/devices/${deviceId}/reconfigure`, { master_key: masterKey, device_id: deviceId, token });
    return response.data;
  },
  // Reubicación de nodo: cambia grupo/aula sin tocar llaves
  reassign: async (deviceId, { groupId = null, classroomId = null, reason } = {}) => {
    const response = await client.post('/devices/reassign', { device_id: deviceId, group_id: groupId, classroom_id: classroomId, reason });
    return response.data;
  },
  // Reprovisionar: rota token + OTA key; el nuevo token se devuelve UNA vez
  reprovision: async (deviceId, reason) => {
    const response = await client.post('/devices/reprovision', { device_id: deviceId, reason });
    return response.data;
  },
};
