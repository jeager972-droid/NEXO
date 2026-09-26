/**
 * behavior API / NEXO Institucional
 * Responsabilidad: Cliente para análisis de riesgo conductual de estudiantes.
 * Dependencias: axios client.js.
 */
import client from './client';

export const behaviorApi = {
  getRiskAnalysis: async () => {
    const response = await client.get('/behavior/risk');
    return response.data ?? { data: [] };
  }
};
