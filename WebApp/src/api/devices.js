/**
 * devices API / NEXO Institucional
 * Responsabilidad: Cliente para dispositivos edge biométricos: listado y envío
 * de comandos M2M (enrolamiento remoto ENROLL_REQUEST, autorización de salida
 * AUTHORIZE_EXIT, eliminación DELETE_STUDENT, FORCE_SYNC, RELOAD_CONFIG).
 * El comando viaja API -> MQTT (fallback Redis) -> nexo-edge.
 * Dependencias: axios client.js.
 */
import client from './client';

export const devicesApi = {
  getAll: async () => {
    const response = await client.get('/devices');
    return response.data?.data ?? [];
  },
  sendCommand: async (deviceId, command, payload = {}) => {
    const response = await client.post(`/devices/command/${deviceId}`, { command, payload });
    return response.data;
  },
  requestEnrollment: (deviceId, { doc, nombre, tel }) =>
    devicesApi.sendCommand(deviceId, 'ENROLL_REQUEST', { doc, nombre, tel }),
  authorizeExit: (deviceId, doc) =>
    devicesApi.sendCommand(deviceId, 'AUTHORIZE_EXIT', { doc }),
};
