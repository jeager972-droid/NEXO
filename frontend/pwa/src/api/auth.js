/**
 * auth API / NEXO Institucional
 * Responsabilidad: Wrappers para endpoints de autenticación: login, verificación 2FA,
 * logout y obtención del usuario actual.
 * Dependencias: axios client.js.
 */
import client from './client';

export const authApi = {
  login: async (email, password) => {
    // Si estamos en desarrollo local, Vite usa el proxy o la URL directa.
    // Asegurémonos de que el path sea limpio.
    const response = await client.post('/auth/login', {
      email: email.trim(),
      password: password
    });
    return response.data;
  },
  verify2FA: async (email, code) => {
    const response = await client.post('/auth/verify-2fa', { email, code });
    return response.data;
  },
  logout: async () => {
    const response = await client.post('/auth/logout');
    return response.data;
  },
  getMe: async () => {
    const response = await client.get('/auth/me');
    return response.data;
  },
  // Refresh token endpoint
  refresh: async (refreshToken) => {
    const response = await client.post('/auth/refresh', { refresh_token: refreshToken });
    return response.data;
  },
};
