import client from './client';

const wrapCommand = (command, params) => ({
  action: 'EXECUTE_COMMAND',
  command,
  params,
});

export const operationsApi = {
  execute: async (command, data, path = `/operations/${command}`) => {
    const response = await client.post(path, wrapCommand(command, data));
    return response.data;
  },
  sos: async (data) => {
    return operationsApi.execute('sos', data, '/operations/sos');
  },
  inasistencia: async (data) => {
    return operationsApi.execute('inasistencia', data, '/operations/inasistencia');
  },
  citacion: async (data) => {
    return operationsApi.execute('citacion', data, '/operations/citacion');
  },
  salida: async (data) => {
    return operationsApi.execute('autorizar_salida', data, '/operations/salida');
  },
  permiso: async (data) => {
    return operationsApi.execute('permiso', data, '/operations/permiso');
  },
};
